<?php

namespace Tests\Feature\Payments;

use App\Models\PlayerRegistration;
use App\Models\RegistrationPayment;
use App\Models\WalletLedger;
use App\Services\Payments\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RefundTest extends TestCase
{
    use PaymentTestHelpers;
    use RefreshDatabase;

    private string $owner;

    private RegistrationPayment $payment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPayments();
        Mail::fake();
        $this->fakeRazorpay();
        $this->owner = $this->tokenFor('organiser@example.com');
        $this->payment = $this->paidRegistration($this->owner, 'pay_A'); // T 52 300, N 50 066, P 1 000
    }

    public function test_full_refund_returns_fees_and_debits_exactly_the_organiser_net(): void
    {
        $this->as($this->owner)->postJson("/v1/payments/{$this->payment->id}/refund")
            ->assertOk()
            ->assertJsonPath('refund.amount', 52_300)
            ->assertJsonPath('refund.organiserDebit', 50_066)
            ->assertJsonPath('payment.status', 'refunded')
            ->assertJsonPath('payment.refundableAmount', 0);

        $p = $this->payment->fresh();
        $this->assertSame(52_300, $p->refunded_amount);
        $this->assertSame(50_066, $p->organiser_refunded);
        $this->assertSame(1000, $p->platform_fee_refunded);
        $this->assertSame(1234, $p->gateway_fee_refunded);
        $this->assertSame('refunded', PlayerRegistration::first()->status);
        $this->assertDatabaseHas('wallet_ledger', ['type' => 'refund', 'amount' => -50_066]);
        $this->assertDatabaseCount('auction_players', 1); // player stays in the pool
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/payments/pay_A/refund') && $r['amount'] === 52_300);
    }

    public function test_two_half_refunds_settle_to_exactly_the_net(): void
    {
        $this->as($this->owner)->postJson("/v1/payments/{$this->payment->id}/refund", ['amount' => 26_150])
            ->assertOk()
            ->assertJsonPath('refund.organiserDebit', 25_033)
            ->assertJsonPath('payment.status', 'partially_refunded')
            ->assertJsonPath('payment.refundableAmount', 26_150);
        $this->as($this->owner)->postJson("/v1/payments/{$this->payment->id}/refund", ['amount' => 26_150])
            ->assertOk()
            ->assertJsonPath('refund.organiserDebit', 25_033);

        $this->assertSame(-50_066, (int) WalletLedger::where('type', 'refund')->sum('amount'));
        $this->assertSame(1000, $this->payment->fresh()->platform_fee_refunded);
    }

    public function test_refund_rules(): void
    {
        $this->as($this->owner)->postJson("/v1/payments/{$this->payment->id}/refund", ['amount' => 60_000])->assertStatus(422);
        $this->as($this->owner)->postJson("/v1/payments/{$this->payment->id}/refund", ['amount' => 50])->assertStatus(422);

        $other = $this->tokenFor('other@example.com');
        $this->as($other)->postJson("/v1/payments/{$this->payment->id}/refund")->assertForbidden();
    }

    public function test_refund_is_blocked_when_the_wallet_cannot_cover_it(): void
    {
        $owner = $this->userId('organiser@example.com');
        $wallet = app(WalletService::class);
        $wallet->withLock($owner, fn () => $wallet->post($owner, WalletLedger::TYPE_PAYOUT, -45_000, now(), null, null, 'drain'));

        $this->as($this->owner)->postJson("/v1/payments/{$this->payment->id}/refund")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Your wallet balance is too low to cover this refund.');
        Http::assertNotSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/refund'));
    }

    public function test_gateway_rejection_surfaces_an_error_and_books_nothing(): void
    {
        // Http::fake() appends and the first matching stub wins, so replace the factory:
        $this->refreshFakes(['*/payments/*/refund' => Http::response(['error' => ['description' => 'nope']], 400)]);

        $this->as($this->owner)->postJson("/v1/payments/{$this->payment->id}/refund")->assertStatus(502);
        $this->assertSame(0, WalletLedger::where('type', 'refund')->count());
        $this->assertSame(0, $this->payment->fresh()->refunded_amount);
    }

    public function test_bank_initiated_refund_webhook_books_the_debit(): void
    {
        $this->webhook('razorpay', 'refund.created', ['refund' => ['entity' => [
            'id' => 'rfnd_EXT', 'entity' => 'refund', 'payment_id' => 'pay_A', 'amount' => 10_000, 'status' => 'pending',
        ]]])->assertOk();

        $this->assertDatabaseHas('payment_refunds', ['razorpay_refund_id' => 'rfnd_EXT', 'initiated_by' => 'external', 'organiser_debit' => 9573]);
        $this->assertDatabaseHas('wallet_ledger', ['type' => 'refund', 'amount' => -9573]);
        $this->assertSame('partially_refunded', $this->payment->fresh()->status);
    }

    public function test_refund_created_webhook_for_our_own_refund_is_a_noop(): void
    {
        $refundId = $this->as($this->owner)->postJson("/v1/payments/{$this->payment->id}/refund")->json('refund.razorpayRefundId');

        $this->webhook('razorpay', 'refund.created', ['refund' => ['entity' => [
            'id' => $refundId, 'payment_id' => 'pay_A', 'amount' => 52_300,
        ]]])->assertOk();

        $this->assertSame(1, WalletLedger::where('type', 'refund')->count());
    }

    public function test_refund_processed_before_created_still_books_once(): void
    {
        $entity = ['id' => 'rfnd_P', 'payment_id' => 'pay_A', 'amount' => 52_300, 'status' => 'processed'];

        $this->webhook('razorpay', 'refund.processed', ['refund' => ['entity' => $entity]])->assertOk();
        $this->webhook('razorpay', 'refund.created', ['refund' => ['entity' => $entity]])->assertOk();

        $this->assertDatabaseHas('payment_refunds', ['razorpay_refund_id' => 'rfnd_P', 'status' => 'processed']);
        $this->assertSame(1, WalletLedger::where('type', 'refund')->count());
    }

    public function test_refund_failed_reverses_the_debit(): void
    {
        $refundId = $this->as($this->owner)->postJson("/v1/payments/{$this->payment->id}/refund")->json('refund.razorpayRefundId');

        $this->webhook('razorpay', 'refund.failed', ['refund' => ['entity' => ['id' => $refundId, 'payment_id' => 'pay_A', 'amount' => 52_300, 'status' => 'failed']]])->assertOk();
        $this->webhook('razorpay', 'refund.failed', ['refund' => ['entity' => ['id' => $refundId, 'payment_id' => 'pay_A', 'amount' => 52_300, 'status' => 'failed']]])->assertOk();

        $this->assertDatabaseHas('wallet_ledger', ['type' => 'refund_reversal', 'amount' => 50_066]);
        $this->assertSame(1, WalletLedger::where('type', 'refund_reversal')->count());
        $p = $this->payment->fresh();
        $this->assertSame('paid', $p->status);
        $this->assertSame(0, $p->refunded_amount);
        $this->assertSame(0, $p->organiser_refunded);
        $this->assertSame('completed', PlayerRegistration::first()->status);
        $this->assertDatabaseHas('payment_refunds', ['razorpay_refund_id' => $refundId, 'status' => 'failed']);
    }

    /** Http::fake() stubs are first-match-wins; swap the factory to replace the defaults. */
    private function refreshFakes(array $overrides): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->fakeRazorpay($overrides);
    }
}
