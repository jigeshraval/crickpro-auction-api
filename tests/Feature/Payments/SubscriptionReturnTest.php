<?php

namespace Tests\Feature\Payments;

use App\Models\Auction;
use App\Models\SubscriptionHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Browser-return activation: Razorpay appends signed params to the Payment Link
 * callback_url; the web posts them to verify-return, which checks the signature
 * and activates. Webhook-independent — works even when Razorpay can't reach us.
 */
class SubscriptionReturnTest extends TestCase
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
        $this->fakeRazorpay([
            '*/payment_links' => fn () => Http::response(['id' => 'plink_TEST', 'short_url' => 'https://rzp.io/rzp/TEST', 'status' => 'created']),
        ]);
        $this->owner = $this->tokenFor('organiser@example.com', 'IN');
        $this->auctionId = (int) $this->createAuction($this->owner)['id'];
    }

    private function checkout(): string
    {
        return $this->as($this->owner)
            ->postJson('/v1/subscription/checkout', ['auctionId' => $this->auctionId, 'teams' => 4, 'returnUrl' => 'http://localhost:16084/subscription/callback'])
            ->assertOk()->json('referenceId');
    }

    /** Razorpay's return signature over link|reference|status|payment with KEY_SECRET. */
    private function returnParams(string $ref, string $status = 'paid', string $linkId = 'plink_TEST', string $paymentId = 'pay_RET'): array
    {
        $sig = hash_hmac('sha256', "{$linkId}|{$ref}|{$status}|{$paymentId}", (string) config('payments.razorpay.key_secret'));

        return [
            'razorpay_payment_link_id' => $linkId,
            'razorpay_payment_link_reference_id' => $ref,
            'razorpay_payment_link_status' => $status,
            'razorpay_payment_id' => $paymentId,
            'razorpay_signature' => $sig,
        ];
    }

    public function test_valid_signed_return_activates_and_syncs(): void
    {
        $ref = $this->checkout();

        $this->as($this->owner)->postJson('/v1/subscription/verify-return', $this->returnParams($ref))
            ->assertOk()->assertJsonPath('result', 'active')->assertJsonPath('auctionId', $this->auctionId);

        $sub = SubscriptionHistory::where('transaction_id', $ref)->first();
        $this->assertTrue((bool) $sub->is_active);
        $this->assertSame(SubscriptionHistory::STATUS_ACTIVE, $sub->status);
        $this->assertSame('return', $sub->metadata['activatedVia'] ?? null);

        $auction = Auction::find($this->auctionId);
        $this->assertSame(4, (int) $auction->max_teams);
        $this->assertTrue((bool) $auction->overlay_access);
    }

    public function test_tampered_signature_is_rejected(): void
    {
        $ref = $this->checkout();
        $params = $this->returnParams($ref);
        $params['razorpay_signature'] = 'deadbeef';

        $this->as($this->owner)->postJson('/v1/subscription/verify-return', $params)
            ->assertStatus(422)->assertJsonPath('result', 'invalid');

        $this->assertSame(SubscriptionHistory::STATUS_PENDING, SubscriptionHistory::where('transaction_id', $ref)->value('status'));
    }

    public function test_unpaid_status_does_not_activate(): void
    {
        $ref = $this->checkout();

        $this->as($this->owner)->postJson('/v1/subscription/verify-return', $this->returnParams($ref, 'cancelled'))
            ->assertOk()->assertJsonPath('result', 'not_paid');

        $this->assertSame(SubscriptionHistory::STATUS_PENDING, SubscriptionHistory::where('transaction_id', $ref)->value('status'));
    }

    public function test_return_is_idempotent(): void
    {
        $ref = $this->checkout();
        $p = $this->returnParams($ref);

        $this->as($this->owner)->postJson('/v1/subscription/verify-return', $p)->assertOk()->assertJsonPath('result', 'active');
        $this->as($this->owner)->postJson('/v1/subscription/verify-return', $p)->assertOk()->assertJsonPath('result', 'active');

        $this->assertSame(1, SubscriptionHistory::where('transaction_id', $ref)->where('is_active', true)->count());
    }
}
