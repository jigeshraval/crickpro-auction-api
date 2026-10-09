<?php

namespace Tests\Feature\Payments;

use App\Mail\OpsAlertMail;
use App\Models\Payout;
use App\Models\PayoutAccount;
use App\Models\WalletLedger;
use App\Services\Payments\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class WithdrawalTest extends TestCase
{
    use PaymentTestHelpers;
    use RefreshDatabase;

    private string $owner;

    private int $ownerId;

    private PayoutAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPayments();
        Mail::fake();
        $this->owner = $this->tokenFor('organiser@example.com');
        $this->ownerId = $this->userId('organiser@example.com');
        $this->bank = $this->account(PayoutAccount::TYPE_BANK, 'XXXXXXXX9012', 'fa_BANK');
    }

    private function account(string $type, string $masked, string $fundAccountId, string $status = PayoutAccount::STATUS_VERIFIED): PayoutAccount
    {
        return PayoutAccount::create([
            'id_owner' => $this->ownerId, 'type' => $type, 'contact_id' => 'cont_TEST', 'fund_account_id' => $fundAccountId,
            'masked_account' => $masked, 'status' => $status, 'is_default' => true,
        ]);
    }

    private function credit(int $amount, bool $available = true, string $key = 'seed'): void
    {
        $wallet = app(WalletService::class);
        $wallet->withLock($this->ownerId, fn () => $wallet->post(
            $this->ownerId, WalletLedger::TYPE_COLLECTION, $amount, $available ? now()->subDay() : now()->addDays(3), null, null, $key,
        ));
    }

    private function withdraw(int $amount, ?int $accountId = null)
    {
        return $this->as($this->owner)->postJson('/v1/wallet/withdrawals', ['amount' => $amount, 'payoutAccountId' => $accountId ?? $this->bank->id]);
    }

    private function available(): int
    {
        return app(WalletService::class)->balances($this->ownerId)['available'];
    }

    public function test_successful_withdrawal_debits_and_sends_the_net_via_imps(): void
    {
        $this->fakeRazorpay();
        $this->credit(50_000);

        $this->withdraw(20_000)
            ->assertCreated()
            ->assertJsonPath('payout.amount', 20_000)
            ->assertJsonPath('payout.fee', 590)
            ->assertJsonPath('payout.netAmount', 19_410)
            ->assertJsonPath('payout.status', 'processing')
            ->assertJsonPath('payout.maskedAccount', 'XXXXXXXX9012');

        $payout = Payout::first();
        $this->assertSame('pout_payout_'.$payout->id, $payout->razorpayx_payout_id);
        $this->assertDatabaseHas('wallet_ledger', ['type' => 'payout', 'amount' => -20_000, 'idempotency_key' => "payout:{$payout->id}"]);
        $this->assertSame(30_000, $this->available());
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/payouts')
            && $r['amount'] === 19_410 && $r['mode'] === 'IMPS' && $r['fund_account_id'] === 'fa_BANK'
            && $r['account_number'] === '2323230000000000' && $r['purpose'] === 'payout' && $r['queue_if_low_balance'] === true
            && $r['reference_id'] === "payout_{$payout->id}" && $r->hasHeader('X-Payout-Idempotency', "payout_{$payout->id}"));
    }

    public function test_vpa_withdrawal_uses_upi_mode(): void
    {
        $this->fakeRazorpay();
        $this->credit(50_000);
        $vpa = $this->account(PayoutAccount::TYPE_VPA, 'ra***@okicici', 'fa_VPA');

        $this->withdraw(10_000, $vpa->id)->assertCreated();

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/payouts') && $r['mode'] === 'UPI');
    }

    public function test_withdrawal_rules(): void
    {
        $this->fakeRazorpay();
        $this->credit(50_000);
        $this->credit(40_000, available: false, key: 'pending');
        $unverified = $this->account(PayoutAccount::TYPE_BANK, 'XXXX1111', 'fa_U', PayoutAccount::STATUS_VALIDATING);

        $this->withdraw(9_999)->assertStatus(422)->assertJsonPath('message', 'Minimum withdrawal is ₹100.');
        $this->withdraw(60_000)->assertStatus(422)->assertJsonPath('message', 'Amount exceeds your available balance.');
        $this->withdraw(10_000, $unverified->id)->assertStatus(422)->assertJsonPath('message', 'This payout account is not verified yet.');

        $other = $this->tokenFor('other@example.com');
        $this->as($other)->postJson('/v1/wallet/withdrawals', ['amount' => 10_000, 'payoutAccountId' => $this->bank->id])->assertNotFound();

        $this->assertSame(0, Payout::count());
    }

    public function test_back_to_back_withdrawals_cannot_overdraw(): void
    {
        $this->fakeRazorpay();
        $this->credit(50_000);

        $this->withdraw(30_000)->assertCreated();
        $this->withdraw(30_000)->assertStatus(422);

        $this->assertSame(20_000, $this->available());
    }

    public function test_rejected_payout_call_reverses_immediately(): void
    {
        $this->fakeRazorpay(['*/payouts' => Http::response(['error' => ['description' => 'Invalid fund account']], 400)]);
        $this->credit(50_000);

        $this->withdraw(20_000)->assertStatus(502);

        $this->assertSame('failed', Payout::first()->status);
        $this->assertDatabaseHas('wallet_ledger', ['type' => 'payout_reversal', 'amount' => 20_000]);
        $this->assertSame(50_000, $this->available());
    }

    public function test_ambiguous_payout_failure_waits_for_the_webhook(): void
    {
        $this->fakeRazorpay(['*/payouts' => Http::response(['error' => ['description' => 'gateway timeout']], 504)]);
        $this->credit(50_000);

        $this->withdraw(20_000)->assertCreated()->assertJsonPath('payout.status', 'queued');

        $payout = Payout::first();
        $this->assertSame(0, WalletLedger::where('type', 'payout_reversal')->count());
        Mail::assertSent(OpsAlertMail::class);

        // RazorpayX later reports it by reference_id
        $this->webhook('razorpayx', 'payout.processed', ['payout' => ['entity' => [
            'id' => 'pout_LATE', 'reference_id' => "payout_{$payout->id}", 'status' => 'processed', 'utr' => 'UTR123',
        ]]])->assertOk();

        $this->assertSame('processed', $payout->fresh()->status);
        $this->assertSame('pout_LATE', $payout->fresh()->razorpayx_payout_id);
    }

    public function test_processed_webhook_stores_the_utr(): void
    {
        $this->fakeRazorpay();
        $this->credit(50_000);
        $this->withdraw(20_000)->assertCreated();
        $payout = Payout::first();

        $this->webhook('razorpayx', 'payout.processed', ['payout' => ['entity' => [
            'id' => $payout->razorpayx_payout_id, 'reference_id' => "payout_{$payout->id}", 'status' => 'processed', 'utr' => 'HDFCN1234567',
        ]]])->assertOk();

        $this->as($this->owner)->getJson('/v1/wallet/withdrawals')
            ->assertOk()
            ->assertJsonPath('items.0.status', 'processed')
            ->assertJsonPath('items.0.utr', 'HDFCN1234567');
    }

    public function test_failed_and_reversed_webhooks_credit_back_exactly_once(): void
    {
        $this->fakeRazorpay();
        $this->credit(50_000);
        $this->withdraw(20_000)->assertCreated();
        $payout = Payout::first();
        $entity = ['id' => $payout->razorpayx_payout_id, 'reference_id' => "payout_{$payout->id}", 'status_details' => ['description' => 'Beneficiary bank offline']];

        $this->webhook('razorpayx', 'payout.failed', ['payout' => ['entity' => $entity + ['status' => 'failed']]])->assertOk();
        $this->webhook('razorpayx', 'payout.reversed', ['payout' => ['entity' => $entity + ['status' => 'reversed']]])->assertOk();

        $this->assertSame(1, WalletLedger::where('type', 'payout_reversal')->count());
        $this->assertSame(50_000, $this->available());
        $this->assertSame('failed', $payout->fresh()->status);
        $this->assertSame('Beneficiary bank offline', $payout->fresh()->failure_reason);
    }

    public function test_reversal_after_processed_credits_back(): void
    {
        $this->fakeRazorpay();
        $this->credit(50_000);
        $this->withdraw(20_000)->assertCreated();
        $payout = Payout::first();
        $entity = ['id' => $payout->razorpayx_payout_id, 'reference_id' => "payout_{$payout->id}"];

        $this->webhook('razorpayx', 'payout.processed', ['payout' => ['entity' => $entity + ['status' => 'processed']]])->assertOk();
        $this->webhook('razorpayx', 'payout.reversed', ['payout' => ['entity' => $entity + ['status' => 'reversed']]])->assertOk();

        $this->assertSame('reversed', $payout->fresh()->status);
        $this->assertSame(50_000, $this->available());
    }

    public function test_negative_balance_blocks_withdrawals_and_alerts_ops(): void
    {
        $this->fakeRazorpay();
        $this->credit(50_000);
        $this->withdraw(50_000)->assertCreated();

        // A bank-initiated refund / chargeback lands after the money left.
        $wallet = app(WalletService::class);
        $wallet->withLock($this->ownerId, function () use ($wallet) {
            $wallet->post($this->ownerId, WalletLedger::TYPE_REFUND, -10_000, now(), null, null, 'late-refund');
            $wallet->alertIfNegative($this->ownerId);
        });
        $this->credit(5_000, key: 'small-new-collection');

        Mail::assertSent(OpsAlertMail::class);
        $this->withdraw(10_000)->assertStatus(422)
            ->assertJsonPath('message', 'Withdrawals are paused because your wallet balance is negative.');
    }
}
