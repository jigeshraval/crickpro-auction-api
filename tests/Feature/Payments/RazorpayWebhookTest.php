<?php

namespace Tests\Feature\Payments;

use App\Mail\OpsAlertMail;
use App\Models\PlayerRegistration;
use App\Models\RazorpayWebhookEvent;
use App\Models\WalletLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RazorpayWebhookTest extends TestCase
{
    use PaymentTestHelpers;
    use RefreshDatabase;

    private string $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPayments();
        $this->fakeRazorpay();
        Mail::fake();
        $this->owner = $this->tokenFor('organiser@example.com');
        $auction = $this->createAuction($this->owner);
        $this->setRegistration($this->owner, $auction['id'], ['registrationMode' => 'razorpay', 'registrationFee' => 50_000, 'feeBearer' => 'player']);
        $this->joinAsPlayer($auction['id'], $this->createInvite($this->owner, $auction['id']))->assertCreated();
    }

    public function test_invalid_signature_is_rejected_and_not_stored(): void
    {
        $qr = $this->latestPayment()->razorpay_qr_id;

        $this->webhook('razorpay', 'qr_code.credited', $this->qrCredited($qr, $this->capturedPayment('pay_A', 52_300)), 'evt_1', 'wrong-secret')
            ->assertStatus(401);

        $this->assertSame(0, RazorpayWebhookEvent::count());
        $this->assertSame('awaiting_payment', PlayerRegistration::first()->status);
    }

    public function test_razorpayx_endpoint_uses_its_own_secret(): void
    {
        $this->webhook('razorpayx', 'payout.processed', ['payout' => ['entity' => ['id' => 'pout_1']]], 'evt_x', 'whsec_gateway')
            ->assertStatus(401);
    }

    public function test_qr_credited_completes_the_registration_exactly_once(): void
    {
        $payment = $this->latestPayment();
        $payload = $this->qrCredited($payment->razorpay_qr_id, $this->capturedPayment('pay_A', 52_300));

        $this->webhook('razorpay', 'qr_code.credited', $payload, 'evt_1')->assertOk()->assertJsonPath('status', 'ok');
        $this->webhook('razorpay', 'qr_code.credited', $payload, 'evt_1')->assertOk()->assertJsonPath('duplicate', true);
        $this->webhook('razorpay', 'qr_code.credited', $payload, 'evt_2')->assertOk(); // Razorpay re-sent with a new id

        $this->assertSame('completed', PlayerRegistration::first()->status);
        $this->assertSame(1, WalletLedger::count());
        $this->assertDatabaseHas('wallet_ledger', ['amount' => 50_000]);
        $this->assertDatabaseCount('auction_players', 1);
        $this->assertSame(2, RazorpayWebhookEvent::count());
        $this->assertNotNull(RazorpayWebhookEvent::where('event_id', 'evt_1')->first()->processed_at);
    }

    public function test_unknown_qr_is_stored_logged_and_acknowledged(): void
    {
        $this->webhook('razorpay', 'qr_code.credited', $this->qrCredited('qr_nobody', $this->capturedPayment('pay_Z', 100)), 'evt_9')
            ->assertOk();

        $this->assertSame('ignored: unknown payment', RazorpayWebhookEvent::where('event_id', 'evt_9')->value('result'));
    }

    public function test_qr_closed_expires_the_registration(): void
    {
        $qr = $this->latestPayment()->razorpay_qr_id;

        $this->webhook('razorpay', 'qr_code.closed', ['qr_code' => ['entity' => ['id' => $qr, 'status' => 'closed', 'close_reason' => 'on_demand']]])
            ->assertOk();

        $this->assertSame('expired', PlayerRegistration::first()->status);
        $this->assertSame('expired', $this->latestPayment()->status);
    }

    public function test_unhandled_events_are_acknowledged(): void
    {
        $this->webhook('razorpay', 'order.paid', ['order' => ['entity' => ['id' => 'order_1']]], 'evt_o')->assertOk();

        $this->assertSame('ignored: unhandled event', RazorpayWebhookEvent::where('event_id', 'evt_o')->value('result'));
    }

    public function test_amount_mismatch_via_webhook_alerts_ops(): void
    {
        $qr = $this->latestPayment()->razorpay_qr_id;

        $this->webhook('razorpay', 'qr_code.credited', $this->qrCredited($qr, $this->capturedPayment('pay_M', 99)))->assertOk();

        $this->assertSame('needs_review', PlayerRegistration::first()->status);
        Mail::assertSent(OpsAlertMail::class);
    }
}
