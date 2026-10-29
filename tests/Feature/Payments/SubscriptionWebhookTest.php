<?php

namespace Tests\Feature\Payments;

use App\Models\Auction;
use App\Models\RazorpayWebhookEvent;
use App\Models\SubscriptionHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The subscription (team-pack) web purchase: a Razorpay Payment Link is created
 * at checkout (pending row), then the `payment_link.paid` webhook activates it.
 * Proves the exact production path with a correctly HMAC-signed webhook body —
 * no network, no dashboard, repeatable.
 */
class SubscriptionWebhookTest extends TestCase
{
    use PaymentTestHelpers;
    use RefreshDatabase;

    private string $owner;

    private int $auctionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPayments();
        // Link flow is international-only (INR uses a UPI QR). A US buyer → USD
        // link. USD 4-team price is 8 (config matrix) → 800 cents.
        config(['subscription.prices.USD.4_teams' => 8]);

        // Fake the payment-link creation Razorpay call (checkout) + the rest.
        $this->fakeRazorpay([
            '*/payment_links' => fn () => Http::response([
                'id' => 'plink_TEST', 'short_url' => 'https://rzp.io/rzp/TEST', 'status' => 'created',
            ]),
        ]);

        // US buyer (USD link path); the login email doubles as the required
        // customer email for international links.
        $this->owner = $this->tokenFor('organiser@example.com', 'US');
        $this->auctionId = (int) $this->createAuction($this->owner)['id'];
    }

    /** reference_id the checkout minted for the pending row. */
    private function checkout(int $teams = 4): string
    {
        $ref = $this->as($this->owner)
            ->postJson('/v1/subscription/checkout', ['auctionId' => $this->auctionId, 'teams' => $teams])
            ->assertOk()
            ->assertJsonPath('gateway', 'razorpay')
            ->json('referenceId');

        $this->assertDatabaseHas('subscription_history', [
            'transaction_id' => $ref, 'source' => 'razorpay', 'status' => SubscriptionHistory::STATUS_PENDING, 'is_active' => 0,
        ]);

        return $ref;
    }

    /** @return array<string,mixed> a payment_link.paid payload for a reference. */
    private function paidPayload(string $ref, int $amountMinor = 800, string $currency = 'USD'): array
    {
        return [
            'payment_link' => ['entity' => [
                'id' => 'plink_TEST', 'reference_id' => $ref, 'currency' => $currency,
                'amount' => $amountMinor, 'amount_paid' => $amountMinor, 'status' => 'paid',
            ]],
            'payment' => ['entity' => ['id' => 'pay_TEST']],
        ];
    }

    public function test_payment_link_paid_activates_the_subscription_and_syncs_the_auction(): void
    {
        $ref = $this->checkout(4);

        $this->webhook('razorpay', 'payment_link.paid', $this->paidPayload($ref), 'evt_sub_1')
            ->assertOk()->assertJsonPath('status', 'ok');

        $sub = SubscriptionHistory::where('transaction_id', $ref)->first();
        $this->assertTrue((bool) $sub->is_active);
        $this->assertSame(SubscriptionHistory::STATUS_ACTIVE, $sub->status);
        $this->assertNotNull($sub->starts_at);

        // syncAuction propagated entitlement onto the auction itself.
        $auction = Auction::find($this->auctionId);
        $this->assertSame(4, (int) $auction->max_teams);
        $this->assertTrue((bool) $auction->overlay_access);

        // The event was recorded and marked completed.
        $this->assertSame('completed', RazorpayWebhookEvent::where('event_id', 'evt_sub_1')->value('result'));
    }

    public function test_activation_is_idempotent(): void
    {
        $ref = $this->checkout(4);

        $this->webhook('razorpay', 'payment_link.paid', $this->paidPayload($ref), 'evt_sub_1')->assertOk();
        // Same event id → stored-once duplicate.
        $this->webhook('razorpay', 'payment_link.paid', $this->paidPayload($ref), 'evt_sub_1')->assertOk()->assertJsonPath('duplicate', true);
        // Razorpay re-sent with a new id → handler no-ops because already active.
        $this->webhook('razorpay', 'payment_link.paid', $this->paidPayload($ref), 'evt_sub_2')->assertOk();

        $this->assertSame(1, SubscriptionHistory::where('transaction_id', $ref)->where('is_active', true)->count());
        $this->assertSame('ignored: already active', RazorpayWebhookEvent::where('event_id', 'evt_sub_2')->value('result'));
    }

    public function test_amount_mismatch_does_not_activate(): void
    {
        $ref = $this->checkout(4);

        // Wrong currency (row is USD) → ignored, stays pending.
        $this->webhook('razorpay', 'payment_link.paid', $this->paidPayload($ref, 800, 'INR'), 'evt_sub_short')->assertOk();

        $this->assertSame(SubscriptionHistory::STATUS_PENDING, SubscriptionHistory::where('transaction_id', $ref)->value('status'));
        $this->assertSame('ignored: amount/currency mismatch', RazorpayWebhookEvent::where('event_id', 'evt_sub_short')->value('result'));
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $ref = $this->checkout(4);

        $this->webhook('razorpay', 'payment_link.paid', $this->paidPayload($ref), 'evt_bad', 'wrong-secret')
            ->assertStatus(401);

        $this->assertSame(0, RazorpayWebhookEvent::count());
        $this->assertSame(SubscriptionHistory::STATUS_PENDING, SubscriptionHistory::where('transaction_id', $ref)->value('status'));
    }
}
