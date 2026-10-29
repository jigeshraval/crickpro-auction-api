<?php

namespace Tests\Feature\Payments;

use App\Models\Auction;
use App\Models\SubscriptionHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Web subscription checkout: POST /v1/subscription/checkout resolves country →
 * gateway/currency. INR → a UPI QR (scan & pay); other razorpay currencies → a
 * Payment Link; PK → WhatsApp. Activation is by webhook (payment_link.paid /
 * qr_code.credited) or the reconcile poll.
 */
class SubscriptionCheckoutTest extends TestCase
{
    use PaymentTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPayments();
        // Pin the asserted INR price so a temporary ₹1 test-override in config
        // doesn't break these assertions.
        config(['subscription.prices.INR.12_teams' => 1999]);
        $this->fakeRazorpay([
            '*/payment_links' => fn (HttpRequest $r) => Http::response([
                'id' => 'plink_TEST', 'short_url' => 'https://rzp.io/i/testlink', 'status' => 'created',
                'reference_id' => $r['reference_id'], 'amount' => $r['amount'], 'currency' => $r['currency'],
            ]),
            // INR uses a UPI QR now.
            '*/payments/qr_codes' => fn (HttpRequest $r) => Http::response([
                'id' => 'qr_TEST', 'entity' => 'qr_code', 'status' => 'active',
                'image_url' => 'https://rzp.io/i/qr_TEST', 'close_by' => now()->addMinutes(15)->timestamp,
                'payment_amount' => $r['payment_amount'],
            ]),
        ]);
    }

    public function test_indian_buyer_gets_a_upi_qr_in_inr(): void
    {
        $token = $this->tokenFor('in@example.com', 'IN');
        $auction = $this->createAuction($token);

        $res = $this->as($token)->postJson('/v1/subscription/checkout', ['auctionId' => $auction['id'], 'teams' => 12])
            ->assertOk()
            ->assertJsonPath('gateway', 'razorpay')
            ->assertJsonPath('mode', 'qr');
        $this->assertNotEmpty($res->json('qrImageUrl'));

        // QR for ₹1,999 → 199900 paise; NOT a payment link.
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'payments/qr_codes') && $r['payment_amount'] === 199900);
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'payment_links'));

        $this->assertDatabaseHas('subscription_history', [
            'id_auction' => $auction['id'], 'plan_id' => '12_teams', 'currency' => 'INR',
            'source' => 'razorpay', 'status' => 'pending', 'is_active' => false, 'max_teams' => 12,
        ]);
    }

    public function test_pakistan_buyer_is_routed_to_whatsapp_with_no_link_or_row(): void
    {
        $token = $this->tokenFor('pk@example.com', 'PK');
        $auction = $this->createAuction($token);

        $this->as($token)->postJson('/v1/subscription/checkout', ['auctionId' => $auction['id'], 'teams' => 12])
            ->assertOk()
            ->assertJsonPath('gateway', 'whatsapp')
            ->assertJsonMissingPath('shortUrl');

        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'payment_links') || str_contains($r->url(), 'payments/qr_codes'));
        $this->assertDatabaseCount('subscription_history', 0);
    }

    public function test_other_country_gets_a_payment_link_in_usd(): void
    {
        $token = $this->tokenFor('us@example.com', 'US');
        $auction = $this->createAuction($token);

        $this->as($token)->postJson('/v1/subscription/checkout', ['auctionId' => $auction['id'], 'teams' => 4])
            ->assertOk()->assertJsonPath('gateway', 'razorpay')->assertJsonPath('mode', 'link');

        // USD 8 → 800 cents, via a payment link (not a QR).
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'payment_links')
            && $r['amount'] === 800 && $r['currency'] === 'USD');
    }

    public function test_an_unknown_tier_is_rejected(): void
    {
        $token = $this->tokenFor('in2@example.com', 'IN');
        $auction = $this->createAuction($token);

        $this->as($token)->postJson('/v1/subscription/checkout', ['auctionId' => $auction['id'], 'teams' => 7])
            ->assertStatus(422);

        $this->assertDatabaseCount('subscription_history', 0);
    }

    public function test_cannot_checkout_someone_elses_auction(): void
    {
        $owner = $this->tokenFor('owner@example.com', 'IN');
        $auction = $this->createAuction($owner);
        $other = $this->tokenFor('other@example.com', 'IN');

        $this->as($other)->postJson('/v1/subscription/checkout', ['auctionId' => $auction['id'], 'teams' => 12])
            ->assertStatus(403);
    }

    public function test_payment_link_paid_webhook_activates_the_pending_pack(): void
    {
        // Link flow is international (USD); INR activation-by-QR is covered in SubscriptionQrTest.
        $token = $this->tokenFor('buyer@example.com', 'US');
        $auction = $this->createAuction($token);

        $ref = $this->as($token)->postJson('/v1/subscription/checkout', ['auctionId' => $auction['id'], 'teams' => 4])
            ->assertOk()->assertJsonPath('mode', 'link')->json('referenceId');

        $payload = [
            'payment_link' => ['entity' => [
                'id' => 'plink_TEST', 'reference_id' => $ref, 'amount' => 800, 'amount_paid' => 800,
                'currency' => 'USD', 'status' => 'paid',
            ]],
            'payment' => ['entity' => ['id' => 'pay_SUB1']],
        ];

        $this->webhook('razorpay', 'payment_link.paid', $payload, 'evt_pl_1')->assertOk();
        // Idempotent: a re-send doesn't double-activate.
        $this->webhook('razorpay', 'payment_link.paid', $payload, 'evt_pl_2')->assertOk();

        $sub = SubscriptionHistory::where('transaction_id', $ref)->first();
        $this->assertTrue((bool) $sub->is_active);
        $this->assertSame('active', $sub->status);

        $this->assertSame(4, (int) Auction::find($auction['id'])->max_teams);
        $this->assertTrue((bool) Auction::find($auction['id'])->overlay_access);
    }

    public function test_webhook_ignores_an_unknown_reference(): void
    {
        $this->webhook('razorpay', 'payment_link.paid', [
            'payment_link' => ['entity' => ['id' => 'plink_X', 'reference_id' => 'sub_nobody', 'amount' => 100, 'amount_paid' => 100, 'currency' => 'INR', 'status' => 'paid']],
            'payment' => ['entity' => ['id' => 'pay_X']],
        ], 'evt_pl_x')->assertOk();

        $this->assertDatabaseCount('subscription_history', 0);
    }
}
