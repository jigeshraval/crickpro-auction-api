<?php

namespace Tests\Feature\Payments;

use App\Mail\OpsAlertMail;
use App\Models\Payout;
use App\Models\PayoutAccount;
use App\Models\WalletLedger;
use App\Services\Payments\WalletService;
use App\Services\Payments\WithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PayoutRecoveryTest extends TestCase
{
    use PaymentTestHelpers;
    use RefreshDatabase;

    private int $ownerId;

    private PayoutAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPayments();
        Mail::fake();
        $this->tokenFor('organiser@example.com');
        $this->ownerId = $this->userId('organiser@example.com');
        $this->bank = PayoutAccount::create([
            'id_owner' => $this->ownerId, 'type' => PayoutAccount::TYPE_BANK, 'contact_id' => 'cont_TEST', 'fund_account_id' => 'fa_BANK',
            'masked_account' => 'XXXXXXXX9012', 'status' => PayoutAccount::STATUS_VERIFIED, 'is_default' => true,
        ]);
        $wallet = app(WalletService::class);
        $wallet->withLock($this->ownerId, fn () => $wallet->post($this->ownerId, WalletLedger::TYPE_COLLECTION, 50_000, now()->subDay(), null, null, 'seed'));
    }

    /** A payout whose debit committed but whose RazorpayX call never happened. */
    private function stuckPayout(int $minutesOld = 30, ?string $razorpayxId = null): Payout
    {
        $payout = Payout::create([
            'id_owner' => $this->ownerId, 'id_payout_account' => $this->bank->id, 'amount' => 20_000, 'fee' => 590, 'net_amount' => 19_410,
            'status' => Payout::STATUS_QUEUED, 'razorpayx_payout_id' => $razorpayxId,
        ]);
        $wallet = app(WalletService::class);
        $wallet->withLock($this->ownerId, fn () => $wallet->post($this->ownerId, WalletLedger::TYPE_PAYOUT, -20_000, now(), 'payout', $payout->id, "payout:{$payout->id}"));
        Payout::whereKey($payout->id)->update(['created_at' => now()->subMinutes($minutesOld)]);

        return $payout->refresh();
    }

    private function available(): int
    {
        return app(WalletService::class)->balances($this->ownerId)['available'];
    }

    public function test_stuck_payout_is_resent_with_the_same_idempotency_key_and_settles(): void
    {
        $this->fakeRazorpay();
        $payout = $this->stuckPayout();

        $this->artisan('payments:recover-payouts')->assertSuccessful();

        $payout->refresh();
        $this->assertSame(Payout::STATUS_PROCESSING, $payout->status);
        $this->assertSame('pout_payout_'.$payout->id, $payout->razorpayx_payout_id);
        $this->assertSame(30_000, $this->available());
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/payouts')
            && $r['reference_id'] === "payout_{$payout->id}" && $r['amount'] === 19_410
            && $r->hasHeader('X-Payout-Idempotency', "payout_{$payout->id}"));
        Http::assertSentCount(2); // reference lookup (nothing found) + re-send
    }

    public function test_a_4xx_on_resend_is_not_proof_of_non_payment_so_it_stays_queued_and_alerts_once(): void
    {
        $this->fakeRazorpay(['*/payouts' => Http::response(['error' => ['description' => 'Invalid fund account']], 400)]);
        $payout = $this->stuckPayout();
        $this->assertSame(30_000, $this->available());

        $this->artisan('payments:recover-payouts')->assertSuccessful();
        $this->artisan('payments:recover-payouts')->assertSuccessful();

        $this->assertSame(Payout::STATUS_QUEUED, $payout->fresh()->status);
        $this->assertSame(WithdrawalService::RESEND_REJECTED, $payout->fresh()->failure_reason);
        $this->assertSame(30_000, $this->available());
        $this->assertDatabaseMissing('wallet_ledger', ['type' => 'payout_reversal']);
        Mail::assertSent(OpsAlertMail::class, 1);
    }

    public function test_payout_found_by_reference_is_applied_without_resending(): void
    {
        $payout = $this->stuckPayout();
        $this->fakeRazorpay(['*/payouts?*' => Http::response(['entity' => 'collection', 'count' => 1, 'items' => [
            ['id' => 'pout_FOUND', 'entity' => 'payout', 'status' => 'processed', 'utr' => 'UTR123', 'reference_id' => "payout_{$payout->id}"],
        ]])]);

        $this->artisan('payments:recover-payouts')->assertSuccessful();

        $payout->refresh();
        $this->assertSame(Payout::STATUS_PROCESSED, $payout->status);
        $this->assertSame('pout_FOUND', $payout->razorpayx_payout_id);
        $this->assertSame('UTR123', $payout->utr);
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'GET' && str_contains($r->url(), '/payouts?')
            && $r['reference_id'] === "payout_{$payout->id}" && $r['account_number'] === '2323230000000000');
        Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'POST');
    }

    public function test_unexpected_lookup_shape_does_not_resend(): void
    {
        $this->fakeRazorpay(['*/payouts?*' => Http::response(['unexpected' => true])]);
        $payout = $this->stuckPayout();

        $this->artisan('payments:recover-payouts')->assertSuccessful();

        Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'POST');
        $this->assertSame(Payout::STATUS_QUEUED, $payout->fresh()->status);
    }

    public function test_payouts_past_the_recovery_window_are_flagged_for_manual_ops_once_and_not_resent(): void
    {
        config(['payments.razorpayx.recovery_max_hours' => 24]);
        $this->fakeRazorpay();
        $payout = $this->stuckPayout(minutesOld: 25 * 60);

        $this->artisan('payments:recover-payouts')->assertSuccessful();
        $this->artisan('payments:recover-payouts')->assertSuccessful();

        Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'POST');
        $this->assertSame(Payout::STATUS_QUEUED, $payout->fresh()->status);
        $this->assertSame('Needs manual reconciliation', $payout->fresh()->failure_reason);
        $this->assertSame(30_000, $this->available());
        Mail::assertSent(OpsAlertMail::class, 1);
    }

    public function test_unknown_outcome_stays_queued_and_alerts_only_once(): void
    {
        $this->fakeRazorpay(['*/payouts' => Http::response(['error' => 'boom'], 503)]);
        $payout = $this->stuckPayout();

        $this->artisan('payments:recover-payouts')->assertSuccessful();
        $this->artisan('payments:recover-payouts')->assertSuccessful();

        $this->assertSame(Payout::STATUS_QUEUED, $payout->fresh()->status);
        $this->assertNull($payout->fresh()->razorpayx_payout_id);
        $this->assertSame(30_000, $this->available());
        Mail::assertSent(OpsAlertMail::class, 1);
    }

    public function test_recent_payouts_and_payouts_with_a_razorpayx_id_are_untouched(): void
    {
        $this->fakeRazorpay();
        $young = $this->stuckPayout(minutesOld: 5);
        $sent = $this->stuckPayout(razorpayxId: 'pout_existing');

        $this->artisan('payments:recover-payouts')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(Payout::STATUS_QUEUED, $young->fresh()->status);
        $this->assertSame(Payout::STATUS_QUEUED, $sent->fresh()->status);
    }
}
