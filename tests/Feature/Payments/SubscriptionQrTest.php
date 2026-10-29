<?php

namespace Tests\Feature\Payments;

use App\Models\Auction;
use App\Models\RazorpayWebhookEvent;
use App\Models\SubscriptionHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * INR subscription via UPI QR (the registration-payments flow). Checkout returns
 * a QR to scan; the `qr_code.credited` webhook — or the reconcile poll — activates
 * the team pack. International currencies keep the Payment Link (not covered here).
 */
class SubscriptionQrTest extends TestCase
{
    use PaymentTestHelpers;
    use RefreshDatabase;

    private string $owner;

    private int $auctionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPayments();
        config(['subscription.prices.INR.4_teams' => 679]);
        config(['payments.poll_min_age_seconds' => 0]);

        $this->fakeRazorpay([
            // QR creation (checkout).
            '*/payments/qr_codes' => fn () => Http::response([
                'id' => 'qr_TEST', 'entity' => 'qr_code', 'status' => 'active',
                'image_url' => 'https://rzp.io/i/qr_TEST', 'close_by' => now()->addMinutes(15)->timestamp,
            ]),
            // QR payments lookup (reconcile backstop) — one captured payment. This
            // override replaces the default empty-items stub (array + on key clash).
            '*/payments/qr_codes/*/payments' => Http::response(['entity' => 'collection', 'count' => 1, 'items' => [
                ['id' => 'pay_QR', 'amount' => 67_900, 'currency' => 'INR', 'status' => 'captured'],
            ]]),
        ]);

        $this->owner = $this->tokenFor('organiser@example.com', 'IN');
        $this->auctionId = (int) $this->createAuction($this->owner)['id'];
    }

    private function checkoutQr(): string
    {
        $res = $this->as($this->owner)
            ->postJson('/v1/subscription/checkout', ['auctionId' => $this->auctionId, 'teams' => 4])
            ->assertOk()
            ->assertJsonPath('gateway', 'razorpay')
            ->assertJsonPath('mode', 'qr');

        $this->assertNotEmpty($res->json('qrImageUrl'));

        return $res->json('referenceId');
    }

    /** qr_code.credited payload with subscription notes + a captured payment. */
    private function creditedPayload(string $ref, int $amountMinor = 67_900, string $currency = 'INR'): array
    {
        return [
            'qr_code' => ['entity' => ['id' => 'qr_TEST', 'notes' => ['referenceId' => $ref, 'kind' => 'subscription', 'auctionId' => (string) $this->auctionId, 'teams' => '4']]],
            'payment' => ['entity' => ['id' => 'pay_QR', 'amount' => $amountMinor, 'currency' => $currency, 'status' => 'captured']],
        ];
    }

    public function test_inr_checkout_returns_a_qr_and_pending_row(): void
    {
        $ref = $this->checkoutQr();

        $sub = SubscriptionHistory::where('transaction_id', $ref)->first();
        $this->assertSame(SubscriptionHistory::STATUS_PENDING, $sub->status);
        $this->assertSame('qr_TEST', $sub->metadata['qrId'] ?? null);
        $this->assertSame('INR', $sub->currency);
    }

    public function test_qr_credited_webhook_activates_subscription(): void
    {
        $ref = $this->checkoutQr();

        $this->webhook('razorpay', 'qr_code.credited', $this->creditedPayload($ref), 'evt_qr_1')
            ->assertOk()->assertJsonPath('status', 'ok');

        $sub = SubscriptionHistory::where('transaction_id', $ref)->first();
        $this->assertTrue((bool) $sub->is_active);
        $this->assertSame(SubscriptionHistory::STATUS_ACTIVE, $sub->status);
        $this->assertSame('qr', $sub->metadata['activatedVia'] ?? null);

        $auction = Auction::find($this->auctionId);
        $this->assertSame(4, (int) $auction->max_teams);
        $this->assertTrue((bool) $auction->overlay_access);

        $this->assertSame('completed', RazorpayWebhookEvent::where('event_id', 'evt_qr_1')->value('result'));
    }

    public function test_registration_qr_still_routes_to_registration_not_subscription(): void
    {
        // A QR credit WITHOUT subscription notes must not hit the subscription path.
        $payload = [
            'qr_code' => ['entity' => ['id' => 'qr_other', 'notes' => ['registration_id' => '999']]],
            'payment' => ['entity' => ['id' => 'pay_X', 'amount' => 100, 'currency' => 'INR', 'status' => 'captured']],
        ];
        $this->webhook('razorpay', 'qr_code.credited', $payload, 'evt_reg')->assertOk();
        // Unknown registration QR → registration path's "unknown payment", not a subscription result.
        $this->assertSame('ignored: unknown payment', RazorpayWebhookEvent::where('event_id', 'evt_reg')->value('result'));
    }

    public function test_amount_mismatch_does_not_activate(): void
    {
        $ref = $this->checkoutQr();

        $this->webhook('razorpay', 'qr_code.credited', $this->creditedPayload($ref, 10_000), 'evt_qr_short')->assertOk();

        $this->assertSame(SubscriptionHistory::STATUS_PENDING, SubscriptionHistory::where('transaction_id', $ref)->value('status'));
        $this->assertSame('ignored: amount/currency mismatch', RazorpayWebhookEvent::where('event_id', 'evt_qr_short')->value('result'));
    }

    public function test_reconcile_poll_activates_a_paid_qr(): void
    {
        $ref = $this->checkoutQr();

        // The QR's webhook was missed; the reconcile poll finds the captured
        // payment (stubbed in setUp) and activates.
        $this->assertSame(1, (new \App\Services\SubscriptionService)->reconcilePending());
        $this->assertTrue((bool) SubscriptionHistory::where('transaction_id', $ref)->value('is_active'));
    }
}
