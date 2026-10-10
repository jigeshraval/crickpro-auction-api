<?php

namespace Tests\Feature\Payments;

use App\Mail\OpsAlertMail;
use App\Models\PlayerRegistration;
use App\Models\WalletLedger;
use App\Services\Payments\PaymentCompletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentCompletionTest extends TestCase
{
    use PaymentTestHelpers;
    use RefreshDatabase;

    private string $owner;

    private array $auction;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPayments();
        Mail::fake();
        $this->owner = $this->tokenFor('organiser@example.com');
        $this->auction = $this->createAuction($this->owner);
        $this->setRegistration($this->owner, $this->auction['id'], ['registrationMode' => 'razorpay', 'registrationFee' => 50_000, 'feeBearer' => 'player']);
    }

    private function join(): string
    {
        $invite = $this->createInvite($this->owner, $this->auction['id']);

        return $this->joinAsPlayer($this->auction['id'], $invite)->assertCreated()->json('registrationToken');
    }

    /** Every QR's payments list returns one captured ₹523 payment with a unique id. */
    private function fakeEveryQrPaid(): void
    {
        $this->fakeRazorpay([
            '*/payments/qr_codes/*/payments' => fn (HttpRequest $r) => Http::response(['items' => [
                $this->capturedPayment('pay_'.Str::between($r->url(), 'qr_codes/', '/payments'), 52_300),
            ]]),
        ]);
    }

    private function pollCalls(): int
    {
        return Http::recorded(fn (HttpRequest $r) => str_ends_with($r->url(), '/payments') && str_contains($r->url(), 'qr_codes/'))->count();
    }

    public function test_completion_credits_the_wallet_and_adds_the_player_exactly_once(): void
    {
        $this->fakeRazorpay();
        $this->join();
        $payment = $this->latestPayment();
        $service = app(PaymentCompletionService::class);

        $service->handleCapturedPayment($this->capturedPayment('pay_A', 52_300), $payment->razorpay_qr_id);
        $service->handleCapturedPayment($this->capturedPayment('pay_A', 52_300), $payment->razorpay_qr_id);

        $payment->refresh();
        $this->assertSame('paid', $payment->status);
        $this->assertSame('pay_A', $payment->razorpay_payment_id);
        $this->assertSame(1234, $payment->gateway_fee);
        $this->assertSame(188, $payment->gateway_tax);
        $this->assertSame(50_000, $payment->organiser_net); // 52300 − 1000 − 1234
        $this->assertTrue($payment->available_at->isSameMinute(now()->addDays(3)));

        $this->assertSame(1, WalletLedger::count());
        $this->assertDatabaseHas('wallet_ledger', ['type' => 'collection', 'amount' => 50_000, 'idempotency_key' => "collection:{$payment->id}"]);
        $this->assertDatabaseCount('auction_players', 1);
        $this->assertSame('completed', PlayerRegistration::first()->status);
        $this->assertDatabaseHas('auction_invitations', ['joined_count' => 1]);
    }

    public function test_non_captured_and_unknown_payments_are_ignored(): void
    {
        $this->fakeRazorpay();
        $this->join();
        $service = app(PaymentCompletionService::class);

        $this->assertNull($service->handleCapturedPayment(['id' => 'pay_X', 'status' => 'authorized', 'amount' => 52_300], $this->latestPayment()->razorpay_qr_id));
        $this->assertNull($service->handleCapturedPayment($this->capturedPayment('pay_Y', 52_300), 'qr_unknown'));
        $this->assertSame(0, WalletLedger::count());
    }

    public function test_amount_mismatch_flags_for_review_and_alerts_ops(): void
    {
        $this->fakeRazorpay();
        $this->join();

        app(PaymentCompletionService::class)->handleCapturedPayment($this->capturedPayment('pay_B', 100), $this->latestPayment()->razorpay_qr_id);

        $this->assertSame('paid', $this->latestPayment()->status);
        $this->assertSame('needs_review', PlayerRegistration::first()->status);
        $this->assertSame(0, WalletLedger::count());
        $this->assertDatabaseCount('auction_players', 0);
        Mail::assertSent(OpsAlertMail::class);
    }

    public function test_late_payment_after_expiry_is_honoured(): void
    {
        $this->fakeRazorpay();
        $token = $this->join();
        $this->travel(16)->minutes();
        $this->guest()->getJson("/v1/public/registrations/{$token}")->assertJsonPath('registrationStatus', 'expired');

        app(PaymentCompletionService::class)->handleCapturedPayment($this->capturedPayment('pay_LATE', 52_300), $this->latestPayment()->razorpay_qr_id);

        $this->assertSame('completed', PlayerRegistration::first()->status);
        $this->assertSame(1, WalletLedger::count());
    }

    public function test_status_endpoint_polls_after_30_seconds_and_completes(): void
    {
        $this->fakeEveryQrPaid();
        $token = $this->join();

        $this->guest()->getJson("/v1/public/registrations/{$token}")->assertJsonPath('registrationStatus', 'awaiting_payment');
        $this->assertSame(0, $this->pollCalls()); // QR younger than 30 s

        $this->travel(31)->seconds();
        $this->guest()->getJson("/v1/public/registrations/{$token}")
            ->assertJsonPath('registrationStatus', 'completed')
            ->assertJsonPath('qr', null);

        $this->assertSame(1, $this->pollCalls());
        $this->assertDatabaseCount('auction_players', 1);
    }

    public function test_status_polling_is_throttled_to_once_per_10_seconds(): void
    {
        $this->fakeRazorpay(); // payments list is empty → stays awaiting_payment
        $token = $this->join();
        $this->travel(31)->seconds();

        $this->guest()->getJson("/v1/public/registrations/{$token}");
        $this->guest()->getJson("/v1/public/registrations/{$token}");
        $this->assertSame(1, $this->pollCalls());

        $this->travel(11)->seconds();
        $this->guest()->getJson("/v1/public/registrations/{$token}");
        $this->assertSame(2, $this->pollCalls());
    }

    public function test_status_marks_the_registration_expired_after_close_by(): void
    {
        $this->fakeRazorpay();
        $token = $this->join();
        $this->travel(16)->minutes();

        $this->guest()->getJson("/v1/public/registrations/{$token}")
            ->assertJsonPath('registrationStatus', 'expired')
            ->assertJsonPath('actions.canGenerateQr', true);
        $this->assertSame('expired', $this->latestPayment()->status);
    }

    public function test_scheduled_command_completes_all_open_qrs(): void
    {
        $this->fakeEveryQrPaid();
        $this->join();
        $this->join();
        $this->travel(31)->seconds();

        $this->artisan('payments:poll-qrs')->assertSuccessful();

        $this->assertSame(2, PlayerRegistration::where('status', 'completed')->count());
        $this->assertSame(2, WalletLedger::count());
    }
}
