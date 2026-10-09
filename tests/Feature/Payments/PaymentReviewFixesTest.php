<?php

namespace Tests\Feature\Payments;

use App\Mail\OpsAlertMail;
use App\Models\AuctionPlayer;
use App\Models\PaymentRefund;
use App\Models\Payout;
use App\Models\PayoutAccount;
use App\Models\PlayerRegistration;
use App\Models\RegistrationPayment;
use App\Models\WalletLedger;
use App\Services\Payments\PaymentRuleException;
use App\Services\Payments\RefundService;
use App\Services\Payments\RegistrationService;
use App\Services\Payments\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Post-implementation review 2 fixes (see the plan's "Post-implementation
 * fixes (review 2)"). Races are simulated by sequencing calls / stale models.
 */
class PaymentReviewFixesTest extends TestCase
{
    use PaymentTestHelpers;
    use RefreshDatabase;

    private string $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPayments();
        Mail::fake();
        $this->owner = $this->tokenFor('organiser@example.com');
    }

    private function ownerId(): int
    {
        return $this->userId('organiser@example.com');
    }

    private function refreshFakes(array $overrides): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->fakeRazorpay($overrides);
    }

    /** ₹500 player-bears auction with one player who joined (QR P1 open). */
    private function joined(): array
    {
        $this->fakeRazorpay();
        $auction = $this->createAuction($this->owner);
        $this->setRegistration($this->owner, $auction['id'], ['registrationMode' => 'razorpay', 'registrationFee' => 50_000, 'feeBearer' => 'player']);
        $this->joinAsPlayer($auction['id'], $this->createInvite($this->owner, $auction['id']))->assertCreated();

        return [$auction, PlayerRegistration::latest('id')->firstOrFail(), $this->latestPayment()];
    }

    private function closeQr(string $qrId, ?string $reason = null): void
    {
        $this->webhook('razorpay', 'qr_code.closed', ['qr_code' => ['entity' => array_filter([
            'id' => $qrId, 'entity' => 'qr_code', 'status' => 'closed', 'close_reason' => $reason,
        ])]])->assertOk();
    }

    private function pay(RegistrationPayment $payment, string $razorpayPaymentId, int $amount = 52_300, int $fee = 1234): void
    {
        $this->webhook('razorpay', 'qr_code.credited', $this->qrCredited($payment->razorpay_qr_id, $this->capturedPayment($razorpayPaymentId, $amount, $fee)))->assertOk();
    }

    /** P1 closed (registration expired) → player taps "new QR" → P2 open. */
    private function withSecondAttempt(): array
    {
        [$auction, $registration, $p1] = $this->joined();
        $this->closeQr($p1->razorpay_qr_id);
        $this->assertSame('expired', $registration->fresh()->status);
        $this->guest()->postJson("/v1/public/registrations/{$registration->public_token}/new-qr")->assertOk();
        $p2 = $this->latestPayment();
        $this->assertNotSame($p1->id, $p2->id);

        return [$auction, $registration, $p1->refresh(), $p2];
    }

    // ── C1: conditional, atomic expiry ────────────────────────────────────

    public function test_expiry_from_a_stale_model_never_expires_a_paid_attempt(): void
    {
        [, $registration, $payment] = $this->joined();
        $stale = RegistrationPayment::findOrFail($payment->id); // status "created" in memory
        $this->pay($payment, 'pay_C1');

        app(RegistrationService::class)->expirePayment($stale);

        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame('completed', $registration->fresh()->status);
    }

    public function test_expiry_does_not_touch_a_registration_whose_newer_attempt_exists(): void
    {
        [, $registration, $p1, $p2] = $this->withSecondAttempt();
        RegistrationPayment::whereKey($p1->id)->update(['status' => 'created']); // stale created attempt

        app(RegistrationService::class)->expirePayment($p1->fresh());

        $this->assertSame('expired', $p1->fresh()->status);
        $this->assertSame('awaiting_payment', $registration->fresh()->status);
        $this->assertSame('created', $p2->fresh()->status);
    }

    public function test_qr_closed_because_paid_is_ignored(): void
    {
        [, $registration, $payment] = $this->joined();

        $this->closeQr($payment->razorpay_qr_id, 'paid');

        $this->assertSame('created', $payment->fresh()->status);
        $this->assertSame('awaiting_payment', $registration->fresh()->status);
        $this->assertDatabaseHas('razorpay_webhook_events', ['type' => 'qr_code.closed', 'result' => 'ignored: closed because paid']);
    }

    // ── C2 / M1: QR issue is conditional and locked ─────────────────────────

    public function test_a_completed_registration_can_never_be_reopened_for_a_new_qr(): void
    {
        [, $registration, $payment] = $this->joined();
        $this->closeQr($payment->razorpay_qr_id);
        $stale = PlayerRegistration::findOrFail($registration->id); // "expired" in memory
        $this->pay($payment, 'pay_LATE'); // late payment on the closed QR completes it
        $this->assertSame('completed', $registration->fresh()->status);

        try {
            app(RegistrationService::class)->issueQr($stale);
            $this->fail('Expected a 409 PaymentRuleException.');
        } catch (PaymentRuleException $e) {
            $this->assertSame(409, $e->status);
        }

        $this->assertSame('completed', $registration->fresh()->status);
        $this->assertSame(1, RegistrationPayment::where('id_registration', $registration->id)->count());
        $this->guest()->postJson("/v1/public/registrations/{$registration->public_token}/new-qr")->assertStatus(409);
    }

    public function test_add_to_pool_early_return_still_marks_the_registration_completed(): void
    {
        [, $registration, $payment] = $this->joined();
        $this->pay($payment, 'pay_POOL');
        PlayerRegistration::whereKey($registration->id)->update(['status' => 'awaiting_payment']); // re-opened by the old race

        app(RegistrationService::class)->addToPool($registration->fresh());

        $this->assertSame('completed', $registration->fresh()->status);
        $this->assertSame(1, AuctionPlayer::count());
    }

    public function test_new_qr_double_tap_issues_a_single_qr(): void
    {
        [, $registration, $payment] = $this->joined();
        $this->closeQr($payment->razorpay_qr_id);
        $stale = PlayerRegistration::findOrFail($registration->id);
        $service = app(RegistrationService::class);

        $first = $service->issueQr($stale);
        $second = $service->issueQr($stale); // the other tap, checked before the first committed

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, RegistrationPayment::where('id_registration', $registration->id)->where('status', 'created')->count());
        Http::assertSentCount(2); // join QR + one regenerated QR
    }

    // ── M2: close other attempts; duplicate payments are flagged + refundable ─

    public function test_completion_closes_the_other_open_qr_attempts(): void
    {
        [, $registration, $p1, $p2] = $this->withSecondAttempt();

        $this->pay($p1, 'pay_OLD'); // late payment on the old QR

        $this->assertSame('completed', $registration->fresh()->status);
        $this->assertSame('expired', $p2->fresh()->status);
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && str_ends_with($r->url(), "/payments/qr_codes/{$p2->razorpay_qr_id}/close"));
    }

    public function test_second_payment_on_a_completed_registration_is_flagged_duplicate_and_refundable(): void
    {
        [$auction, $registration, $p1, $p2] = $this->withSecondAttempt();
        $this->pay($p2, 'pay_FIRST');
        $this->pay($p1, 'pay_SECOND'); // late payment on the old, already-closed QR

        $this->assertSame('completed', $registration->fresh()->status);
        $this->assertTrue($p1->fresh()->is_duplicate);
        $this->assertFalse($p2->fresh()->is_duplicate);
        $this->assertSame(1, AuctionPlayer::count());
        $this->assertSame(2, WalletLedger::where('type', 'collection')->count()); // credited, not auto-refunded
        Mail::assertSent(OpsAlertMail::class);

        $this->as($this->owner)->getJson("/v1/auctions/{$auction['id']}/collections?duplicate=1")
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $p1->id)
            ->assertJsonPath('items.0.isDuplicate', true)
            ->assertJsonPath('items.0.refundableAmount', 52_300);

        $this->as($this->owner)->postJson("/v1/payments/{$p1->id}/refund")->assertOk()
            ->assertJsonPath('refund.amount', 52_300);
        $this->assertSame('refunded', $p1->fresh()->status);
        $this->assertSame('paid', $p2->fresh()->status);
    }

    // ── I1: amount mismatch ──────────────────────────────────────────────

    public function test_amount_mismatch_books_no_organiser_net_and_blocks_organiser_refunds(): void
    {
        [, $registration, $payment] = $this->joined();
        $this->pay($payment, 'pay_MISMATCH', 40_000);

        $payment->refresh();
        $this->assertSame(0, $payment->organiser_net);
        $this->assertSame('needs_review', $registration->fresh()->status);
        $this->assertSame(0, WalletLedger::count());

        $this->as($this->owner)->postJson("/v1/payments/{$payment->id}/refund")
            ->assertStatus(422)->assertJsonPath('message', 'Under review by CrickPro.');
        Http::assertNotSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/refund'));

        // CrickPro ops refund from the dashboard / a chargeback: organiser is debited nothing.
        $this->webhook('razorpay', 'refund.created', ['refund' => ['entity' => ['id' => 'rfnd_OPS', 'payment_id' => 'pay_MISMATCH', 'amount' => 20_000, 'status' => 'processed']]])->assertOk();
        $this->webhook('razorpay', 'payment.dispute.created', ['dispute' => ['entity' => ['id' => 'disp_M', 'payment_id' => 'pay_MISMATCH', 'amount' => 20_000]]])->assertOk();
        $this->assertSame(0, app(WalletService::class)->balances($this->ownerId())['balance']);
        $this->assertDatabaseHas('payment_refunds', ['razorpay_refund_id' => 'rfnd_OPS', 'organiser_debit' => 0]);
        $this->assertDatabaseHas('payment_disputes', ['razorpay_dispute_id' => 'disp_M', 'organiser_hold' => 0]);
    }

    public function test_amount_mismatch_on_an_already_completed_registration_keeps_it_completed(): void
    {
        [, $registration, $p1, $p2] = $this->withSecondAttempt();
        $this->pay($p2, 'pay_GOOD');
        $this->pay($p1, 'pay_ODD', 10_000);

        $this->assertSame('completed', $registration->fresh()->status);
        $this->assertSame(0, $p1->fresh()->organiser_net);
    }

    // ── M3: actual fee larger than the organiser share ─────────────────────

    public function test_gateway_fee_above_the_share_clamps_net_to_zero_and_alerts(): void
    {
        [, $registration, $payment] = $this->joined();
        $this->pay($payment, 'pay_FEE', 52_300, fee: 52_000);

        $payment->refresh();
        $this->assertSame(0, $payment->organiser_net);
        $this->assertSame('completed', $registration->fresh()->status);
        $this->assertDatabaseHas('wallet_ledger', ['type' => 'collection', 'amount' => 0]);
        Mail::assertSent(OpsAlertMail::class);

        // Refund then works and debits nothing (share() never sees a negative portion).
        $this->as($this->owner)->postJson("/v1/payments/{$payment->id}/refund")->assertOk()->assertJsonPath('refund.organiserDebit', 0);
    }

    // ── I2: disputes are capped; lost is terminal; won restores ─────────────

    public function test_dispute_is_capped_at_the_unrefunded_amount_and_remaining_organiser_share(): void
    {
        $this->fakeRazorpay();
        $payment = $this->paidRegistration($this->owner, 'pay_D');
        $this->as($this->owner)->postJson("/v1/payments/{$payment->id}/refund", ['amount' => 26_150])->assertOk();
        $payment->refresh();
        $remainingNet = $payment->organiser_net - $payment->organiser_refunded;

        $this->webhook('razorpay', 'payment.dispute.created', ['dispute' => ['entity' => ['id' => 'disp_1', 'payment_id' => 'pay_D', 'amount' => 52_300]]])->assertOk();
        $this->assertDatabaseHas('payment_disputes', ['razorpay_dispute_id' => 'disp_1', 'amount' => 26_150, 'organiser_hold' => $remainingNet]);

        // A second dispute can't hold more than what is left (nothing).
        $this->webhook('razorpay', 'payment.dispute.created', ['dispute' => ['entity' => ['id' => 'disp_2', 'payment_id' => 'pay_D', 'amount' => 52_300]]])->assertOk();
        $this->assertDatabaseHas('payment_disputes', ['razorpay_dispute_id' => 'disp_2', 'organiser_hold' => 0]);
        $this->assertSame(0, app(WalletService::class)->balances($this->ownerId())['balance']);
    }

    public function test_won_dispute_restores_partially_refunded_and_lost_is_terminal(): void
    {
        $this->fakeRazorpay();
        $payment = $this->paidRegistration($this->owner, 'pay_W');
        $this->as($this->owner)->postJson("/v1/payments/{$payment->id}/refund", ['amount' => 10_000])->assertOk();

        $this->webhook('razorpay', 'payment.dispute.created', ['dispute' => ['entity' => ['id' => 'disp_W', 'payment_id' => 'pay_W', 'amount' => 10_000]]])->assertOk();
        $this->webhook('razorpay', 'payment.dispute.won', ['dispute' => ['entity' => ['id' => 'disp_W', 'payment_id' => 'pay_W']]])->assertOk();
        $this->assertSame('partially_refunded', $payment->fresh()->status);

        $this->webhook('razorpay', 'payment.dispute.created', ['dispute' => ['entity' => ['id' => 'disp_L', 'payment_id' => 'pay_W', 'amount' => 10_000]]])->assertOk();
        $this->webhook('razorpay', 'payment.dispute.lost', ['dispute' => ['entity' => ['id' => 'disp_L', 'payment_id' => 'pay_W']]])->assertOk();
        $this->assertSame('dispute_lost', $payment->fresh()->status);

        $this->as($this->owner)->postJson("/v1/payments/{$payment->id}/refund")->assertStatus(422);
        $this->as($this->owner)->getJson("/v1/auctions/{$payment->id_auction}/collections?status=dispute_lost")
            ->assertOk()->assertJsonPath('items.0.status', 'dispute_lost');
    }

    // ── I3 / I4 / M8: refund webhook ordering ─────────────────────────────

    public function test_refund_failed_before_created_is_remembered_and_created_is_a_noop(): void
    {
        $this->fakeRazorpay();
        $payment = $this->paidRegistration($this->owner, 'pay_R');
        $entity = ['id' => 'rfnd_X', 'entity' => 'refund', 'payment_id' => 'pay_R', 'amount' => 52_300];

        $this->webhook('razorpay', 'refund.failed', ['refund' => ['entity' => $entity + ['status' => 'failed']]])->assertOk();
        $this->webhook('razorpay', 'refund.created', ['refund' => ['entity' => $entity + ['status' => 'pending']]])->assertOk();
        $this->webhook('razorpay', 'refund.processed', ['refund' => ['entity' => $entity + ['status' => 'processed']]])->assertOk();

        $this->assertDatabaseHas('payment_refunds', ['razorpay_refund_id' => 'rfnd_X', 'status' => 'failed', 'organiser_debit' => 0]);
        $this->assertSame(0, WalletLedger::whereIn('type', ['refund', 'refund_reversal'])->count());
        $this->assertSame(0, $payment->fresh()->refunded_amount);
        $this->assertSame('paid', $payment->fresh()->status);
    }

    public function test_processed_upgrades_a_refund_booked_concurrently_by_refund_created(): void
    {
        $this->fakeRazorpay();
        $this->paidRegistration($this->owner, 'pay_P');
        $entity = ['id' => 'rfnd_P', 'entity' => 'refund', 'payment_id' => 'pay_P', 'amount' => 10_000];

        // refund.processed saw no row, then refund.created booked it before processed took the lock:
        app(RefundService::class)->onRefundCreated($entity, PaymentRefund::STATUS_CREATED);
        app(RefundService::class)->onRefundCreated($entity, PaymentRefund::STATUS_PROCESSED);

        $this->assertDatabaseHas('payment_refunds', ['razorpay_refund_id' => 'rfnd_P', 'status' => 'processed']);
        $this->assertSame(1, WalletLedger::where('type', 'refund')->count());
    }

    // ── M4: refund idempotency + unknown outcome ─────────────────────────

    public function test_organiser_refund_sends_an_idempotency_key(): void
    {
        $this->fakeRazorpay();
        $payment = $this->paidRegistration($this->owner, 'pay_I');

        $this->as($this->owner)->postJson("/v1/payments/{$payment->id}/refund", ['amount' => 10_000])->assertOk();
        $this->as($this->owner)->postJson("/v1/payments/{$payment->id}/refund", ['amount' => 10_000])->assertOk();

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/refund') && $r->hasHeader('X-Refund-Idempotency', "refund_{$payment->id}_1"));
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/refund') && $r->hasHeader('X-Refund-Idempotency', "refund_{$payment->id}_2"));
    }

    public function test_refund_with_an_unknown_gateway_outcome_returns_202_and_books_nothing_yet(): void
    {
        $this->fakeRazorpay();
        $payment = $this->paidRegistration($this->owner, 'pay_T');
        $this->refreshFakes(['*/payments/*/refund' => Http::response(['error' => 'upstream'], 504)]);

        $this->as($this->owner)->postJson("/v1/payments/{$payment->id}/refund")
            ->assertStatus(202)
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('message', 'Refund is being processed.');
        $this->assertSame(0, PaymentRefund::count());

        // The refund.created webhook then books it.
        $this->webhook('razorpay', 'refund.created', ['refund' => ['entity' => ['id' => 'rfnd_T', 'payment_id' => 'pay_T', 'amount' => 52_300, 'status' => 'pending']]])->assertOk();
        $this->assertSame('refunded', $payment->fresh()->status);
    }

    // ── I6: processed after a reversal ────────────────────────────────────

    public function test_processed_after_a_reversal_alerts_ops_and_changes_nothing(): void
    {
        $this->fakeRazorpay();
        $ownerId = $this->ownerId();
        $bank = PayoutAccount::create([
            'id_owner' => $ownerId, 'type' => PayoutAccount::TYPE_BANK, 'contact_id' => 'cont_TEST', 'fund_account_id' => 'fa_BANK',
            'masked_account' => 'XXXXXXXX9012', 'status' => PayoutAccount::STATUS_VERIFIED, 'is_default' => true,
        ]);
        $wallet = app(WalletService::class);
        $wallet->withLock($ownerId, fn () => $wallet->post($ownerId, WalletLedger::TYPE_COLLECTION, 50_000, now()->subDay(), null, null, 'seed'));
        $this->as($this->owner)->postJson('/v1/wallet/withdrawals', ['amount' => 20_000, 'payoutAccountId' => $bank->id])->assertCreated();
        $payout = Payout::firstOrFail();
        $entity = ['id' => $payout->razorpayx_payout_id, 'reference_id' => "payout_{$payout->id}"];

        $this->webhook('razorpayx', 'payout.failed', ['payout' => ['entity' => $entity + ['status' => 'failed']]])->assertOk();
        Mail::assertNothingSent();
        $this->webhook('razorpayx', 'payout.processed', ['payout' => ['entity' => $entity + ['status' => 'processed', 'utr' => 'UTR9']]])->assertOk();

        $this->assertSame('failed', $payout->fresh()->status);
        $this->assertSame(50_000, $wallet->balances($ownerId)['balance']);
        Mail::assertSent(OpsAlertMail::class, 1);
    }

    // ── I7: webhook reclaim ───────────────────────────────────────────────

    private function storeUnprocessedEvent(string $eventId, string $event, int $ageSeconds): void
    {
        DB::table('razorpay_webhook_events')->insert([
            'source' => 'razorpay', 'event_id' => $eventId, 'type' => $event, 'payload' => '{}',
            'created_at' => now()->subSeconds($ageSeconds), 'updated_at' => now()->subSeconds($ageSeconds),
        ]);
    }

    public function test_a_stale_unprocessed_event_is_reclaimed_and_processed(): void
    {
        [, $registration, $payment] = $this->joined();
        $this->storeUnprocessedEvent('evt_CRASHED', 'qr_code.credited', 300);

        $this->webhook('razorpay', 'qr_code.credited', $this->qrCredited($payment->razorpay_qr_id, $this->capturedPayment('pay_RECLAIM', 52_300)), 'evt_CRASHED')
            ->assertOk()->assertJsonMissing(['duplicate' => true]);

        $this->assertSame('completed', $registration->fresh()->status);
        $this->assertNotNull(DB::table('razorpay_webhook_events')->where('event_id', 'evt_CRASHED')->value('processed_at'));
    }

    public function test_a_fresh_unprocessed_duplicate_returns_500_so_razorpay_retries(): void
    {
        [, $registration, $payment] = $this->joined();
        $this->storeUnprocessedEvent('evt_INFLIGHT', 'qr_code.credited', 10);

        $this->webhook('razorpay', 'qr_code.credited', $this->qrCredited($payment->razorpay_qr_id, $this->capturedPayment('pay_INFLIGHT', 52_300)), 'evt_INFLIGHT')
            ->assertStatus(500);

        $this->assertSame('awaiting_payment', $registration->fresh()->status);
    }

    public function test_a_processed_duplicate_is_still_a_200_noop(): void
    {
        [, , $payment] = $this->joined();
        $body = $this->qrCredited($payment->razorpay_qr_id, $this->capturedPayment('pay_DUP', 52_300));
        $this->webhook('razorpay', 'qr_code.credited', $body, 'evt_DUP')->assertOk();

        $this->webhook('razorpay', 'qr_code.credited', $body, 'evt_DUP')->assertOk()->assertJsonPath('duplicate', true);
    }

    // ── M6 / M7 ──────────────────────────────────────────────────────────

    public function test_webhook_routes_allow_1200_requests_a_minute(): void
    {
        foreach (['razorpay', 'razorpayx'] as $source) {
            $route = app('router')->getRoutes()->match(Request::create("/v1/webhook/{$source}", 'POST'));
            $this->assertContains('throttle:1200,1', $route->gatherMiddleware());
        }
    }

    public function test_prune_command_deletes_only_old_processed_webhook_events(): void
    {
        $rows = [
            'old_done' => [now()->subDays(91), now()->subDays(91)],
            'new_done' => [now()->subDays(10), now()->subDays(10)],
            'old_open' => [null, now()->subDays(120)],
        ];
        foreach ($rows as $id => [$processedAt, $createdAt]) {
            DB::table('razorpay_webhook_events')->insert([
                'source' => 'razorpay', 'event_id' => $id, 'type' => 'x', 'payload' => '{}',
                'processed_at' => $processedAt, 'created_at' => $createdAt, 'updated_at' => $createdAt,
            ]);
        }

        $this->artisan('payments:prune-webhook-events')->assertSuccessful();

        $this->assertSame(['new_done', 'old_open'], DB::table('razorpay_webhook_events')->orderBy('event_id')->pluck('event_id')->all());
    }
}
