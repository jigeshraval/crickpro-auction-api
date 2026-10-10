<?php

namespace Tests\Feature\Payments;

use App\Models\RegistrationPayment;
use App\Models\WalletLedger;
use App\Services\Payments\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DisputeTest extends TestCase
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
        $this->payment = $this->paidRegistration($this->owner, 'pay_A');
    }

    private function dispute(string $event, int $amount = 52_300, ?string $eventId = null): void
    {
        $this->webhook('razorpay', $event, [
            'payment' => ['entity' => $this->capturedPayment('pay_A', 52_300)],
            'dispute' => ['entity' => ['id' => 'disp_1', 'entity' => 'dispute', 'payment_id' => 'pay_A', 'amount' => $amount, 'phase' => 'chargeback']],
        ], $eventId)->assertOk();
    }

    private function ownerBalance(): int
    {
        return app(WalletService::class)->balances($this->userId('organiser@example.com'))['balance'];
    }

    public function test_dispute_created_holds_the_organiser_share(): void
    {
        $this->dispute('payment.dispute.created');
        $this->dispute('payment.dispute.created'); // retried with a new event id

        $this->assertSame(1, WalletLedger::where('type', 'dispute_hold')->count());
        $this->assertDatabaseHas('wallet_ledger', ['type' => 'dispute_hold', 'amount' => -50_000]);
        $this->assertSame('disputed', $this->payment->fresh()->status);
        $this->assertDatabaseHas('payment_disputes', ['razorpay_dispute_id' => 'disp_1', 'status' => 'open', 'organiser_hold' => 50_000]);
        $this->assertSame(0, $this->ownerBalance());
    }

    public function test_partial_dispute_holds_a_proportional_share(): void
    {
        $this->dispute('payment.dispute.created', 10_000);

        $this->assertDatabaseHas('wallet_ledger', ['type' => 'dispute_hold', 'amount' => -9560]);
    }

    public function test_won_releases_the_hold_and_restores_the_status(): void
    {
        $this->dispute('payment.dispute.created');
        $this->dispute('payment.dispute.won');
        $this->dispute('payment.dispute.won');

        $this->assertSame(1, WalletLedger::where('type', 'dispute_release')->count());
        $this->assertSame(50_000, $this->ownerBalance());
        $this->assertSame('paid', $this->payment->fresh()->status);
        $this->assertDatabaseHas('payment_disputes', ['razorpay_dispute_id' => 'disp_1', 'status' => 'won']);
    }

    public function test_lost_keeps_the_money_out_and_labels_it_a_loss(): void
    {
        $this->dispute('payment.dispute.created');
        $this->dispute('payment.dispute.lost');

        $this->assertDatabaseHas('wallet_ledger', ['type' => 'dispute_release', 'amount' => 50_000]);
        $this->assertDatabaseHas('wallet_ledger', ['type' => 'dispute_loss', 'amount' => -50_000]);
        $this->assertSame(0, $this->ownerBalance());
        $this->assertSame('dispute_lost', $this->payment->fresh()->status); // terminal (review 2, I2)
        $this->assertDatabaseHas('payment_disputes', ['razorpay_dispute_id' => 'disp_1', 'status' => 'lost']);
    }

    public function test_closed_only_changes_the_dispute_status(): void
    {
        $this->dispute('payment.dispute.created');
        $this->dispute('payment.dispute.closed');

        $this->assertDatabaseHas('payment_disputes', ['razorpay_dispute_id' => 'disp_1', 'status' => 'closed']);
        $this->assertSame(1, WalletLedger::where('type', 'like', 'dispute%')->count());
    }

    public function test_dispute_on_unknown_payment_is_ignored(): void
    {
        $this->webhook('razorpay', 'payment.dispute.created', [
            'dispute' => ['entity' => ['id' => 'disp_X', 'payment_id' => 'pay_UNKNOWN', 'amount' => 100]],
        ])->assertOk();

        $this->assertDatabaseCount('payment_disputes', 0);
    }

    public function test_won_or_lost_after_closed_still_settles(): void
    {
        $this->dispute('payment.dispute.created');
        $this->dispute('payment.dispute.closed');
        $this->dispute('payment.dispute.won');

        $this->assertSame(1, WalletLedger::where('type', 'dispute_release')->count());
        $this->assertDatabaseHas('payment_disputes', ['razorpay_dispute_id' => 'disp_1', 'status' => 'won']);
    }
}
