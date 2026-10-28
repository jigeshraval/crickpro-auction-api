<?php

namespace Tests\Feature\Payments;

use App\Models\Auction;
use App\Models\SubscriptionHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Web subscription purchase via Razorpay Payment Links: POST /v1/subscription/checkout
 * resolves country → gateway, and the payment_link.paid webhook activates the pack.
 */
class SubscriptionCheckoutTest extends TestCase
{
    use PaymentTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPayments();
        $this->fakeRazorpay([
            '*/payment_links' => fn (HttpRequest $r) => Http::response([
                'id' => 'plink_TEST', 'short_url' => 'https://rzp.io/i/testlink', 'status' => 'created',
                'reference_id' => $r['reference_id'], 'amount' => $r['amount'], 'currency' => $r['currency'],
            ]),
        ]);
    }

    public function test_indian_buyer_gets_a_razorpay_payment_link_in_inr(): void
    {
        $token = $this->tokenFor('in@example.com', 'IN');
        $auction = $this->createAuction($token);

        $res = $this->as($token)->postJson('/v1/subscription/checkout', ['auctionId' => $auction['id'], 'teams' => 12])
            ->assertOk()
            ->assertJsonPath('gateway', 'razorpay')
            ->assertJsonPath('shortUrl', 'https://rzp.io/i/testlink');

        // Link created for ₹1,999 → 199900 paise, INR.
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'payment_links')
            && $r['amount'] === 199900 && $r['currency'] === 'INR'
            && $r['reference_id'] === $res->json('referenceId'));

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

        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'payment_links'));
        $this->assertDatabaseCount('subscription_history', 0);
    }

    public function test_other_country_prices_in_usd(): void
    {
        $token = $this->tokenFor('us@example.com', 'US');
        $auction = $this->createAuction($token);

        $this->as($token)->postJson('/v1/subscription/checkout', ['auctionId' => $auction['id'], 'teams' => 4])
            ->assertOk()->assertJsonPath('gateway', 'razorpay');

        // USD 8 → 800 cents.
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
        $token = $this->tokenFor('buyer@example.com', 'IN');
        $auction = $this->createAuction($token);

        $ref = $this->as($token)->postJson('/v1/subscription/checkout', ['auctionId' => $auction['id'], 'teams' => 12])
            ->assertOk()->json('referenceId');

        $payload = [
            'payment_link' => ['entity' => [
                'id' => 'plink_TEST', 'reference_id' => $ref, 'amount' => 199900, 'amount_paid' => 199900,
                'currency' => 'INR', 'status' => 'paid',
            ]],
            'payment' => ['entity' => ['id' => 'pay_SUB1']],
        ];

        $this->webhook('razorpay', 'payment_link.paid', $payload, 'evt_pl_1')->assertOk();
        // Idempotent: a re-send doesn't double-activate.
        $this->webhook('razorpay', 'payment_link.paid', $payload, 'evt_pl_2')->assertOk();

        $sub = SubscriptionHistory::where('transaction_id', $ref)->first();
        $this->assertTrue((bool) $sub->is_active);
        $this->assertSame('active', $sub->status);

        // Entitlement denormalised onto the auction.
        $this->assertSame(12, (int) Auction::find($auction['id'])->max_teams);
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
