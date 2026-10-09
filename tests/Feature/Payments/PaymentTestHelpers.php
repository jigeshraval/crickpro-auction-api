<?php

namespace Tests\Feature\Payments;

use App\Models\Currency;
use App\Models\OverlayTemplate;
use App\Models\RegistrationPayment;
use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

trait PaymentTestHelpers
{
    protected function bootPayments(): void
    {
        OverlayTemplate::create(['code' => 'nakshatra', 'name' => 'Nakshatra']);
        Currency::create(['id' => 1, 'code' => 'INR', 'name' => 'Indian Rupee', 'symbol' => '₹']);

        config([
            'payments.razorpay.key_id' => 'rzp_test_key',
            'payments.razorpay.key_secret' => 'rzp_test_secret',
            'payments.razorpay.webhook_secret' => 'whsec_gateway',
            'payments.razorpayx.key_id' => 'rzp_test_key',
            'payments.razorpayx.key_secret' => 'rzp_test_secret',
            'payments.razorpayx.account_number' => '2323230000000000',
            'payments.razorpayx.webhook_secret' => 'whsec_x',
            'payments.ops_email' => 'ops@example.com',
        ]);

        Http::preventStrayRequests();
    }

    /** Users are Indian by default (registration payments are India-only — Task 2). */
    protected function tokenFor(string $email, ?string $country = 'IN'): string
    {
        User::factory()->create(['email' => $email, 'password' => bcrypt('Passw0rd1'), 'country' => $country]);
        $this->app['auth']->forgetGuards();

        return $this->postJson('/v1/auth/login-password', ['email' => $email, 'password' => 'Passw0rd1'])->json('token');
    }

    protected function userId(string $email): int
    {
        return (int) User::where('email', $email)->value('id');
    }

    protected function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    protected function guest(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withoutHeader('Authorization');
    }

    protected function createAuction(string $token, string $name = 'Payments Cup'): array
    {
        return $this->as($token)->postJson('/v1/auctions/quick-start', ['name' => $name])->assertSuccessful()->json('auction');
    }

    protected function createInvite(string $token, int $auctionId): string
    {
        return $this->as($token)->postJson("/v1/auctions/{$auctionId}/invitations", [])->assertCreated()->json('invitation.token');
    }

    protected function setRegistration(string $token, int $auctionId, array $settings): void
    {
        $this->as($token)->putJson("/v1/auctions/{$auctionId}/registration-settings", $settings)->assertOk();
    }

    protected function joinAsPlayer(int $auctionId, string $inviteToken, string $name = 'Rahul Player'): TestResponse
    {
        return $this->guest()->postJson("/v1/public/invitations/{$auctionId}/{$inviteToken}/join", [
            'name' => $name, 'role' => 'batter', 'phone' => '9999999999',
        ]);
    }

    /**
     * Fake every Razorpay/RazorpayX endpoint we call. Pass overrides keyed by the
     * same URL patterns to replace a default (overrides are matched first).
     * QR ids are deterministic: "qr_{registration_payments.id}".
     */
    protected function fakeRazorpay(array $overrides = []): void
    {
        Http::fake($overrides + [
            '*/payments/qr_codes/*/payments' => Http::response(['entity' => 'collection', 'count' => 0, 'items' => []]),
            '*/payments/qr_codes/*/close' => fn (HttpRequest $r) => Http::response([
                'id' => Str::between($r->url(), '/qr_codes/', '/close'), 'entity' => 'qr_code', 'status' => 'closed', 'close_reason' => 'on_demand',
            ]),
            // Payout lookup by reference_id (recovery) — RazorpayX has none by default.
            '*/payouts?*' => Http::response(['entity' => 'collection', 'count' => 0, 'items' => []]),
            '*/payments/qr_codes' => fn (HttpRequest $r) => Http::response([
                'id' => 'qr_'.$r['notes']['payment_id'],
                'entity' => 'qr_code',
                'status' => 'active',
                'image_url' => 'https://rzp.io/i/qr_'.$r['notes']['payment_id'],
                'payment_amount' => $r['payment_amount'],
                'close_by' => $r['close_by'],
            ]),
            '*/payments/*/refund' => fn (HttpRequest $r) => Http::response([
                'id' => 'rfnd_'.Str::random(10),
                'entity' => 'refund',
                'amount' => $r['amount'],
                'payment_id' => Str::between($r->url(), '/payments/', '/refund'),
                'status' => 'processed',
            ]),
            '*/contacts' => Http::response(['id' => 'cont_TEST', 'entity' => 'contact']),
            '*/fund_accounts/validations' => fn (HttpRequest $r) => Http::response([
                'id' => 'fav_'.$r['fund_account']['id'], 'entity' => 'fund_account.validation', 'status' => 'created',
            ]),
            '*/fund_accounts' => fn (HttpRequest $r) => Http::response([
                'id' => 'fa_'.substr(md5(json_encode($r->data())), 0, 10), 'entity' => 'fund_account', 'account_type' => $r['account_type'],
            ]),
            '*/payouts' => fn (HttpRequest $r) => Http::response([
                'id' => 'pout_'.$r['reference_id'], 'entity' => 'payout', 'status' => 'processing',
                'amount' => $r['amount'], 'reference_id' => $r['reference_id'],
            ]),
        ]);
    }

    /** POST a correctly signed webhook (raw body, like Razorpay does). */
    protected function webhook(string $source, string $event, array $payload, ?string $eventId = null, ?string $secret = null): TestResponse
    {
        $body = json_encode([
            'entity' => 'event', 'account_id' => 'acc_TEST', 'event' => $event,
            'contains' => array_keys($payload), 'payload' => $payload, 'created_at' => now()->timestamp,
        ]);
        $secret ??= $source === 'razorpayx' ? 'whsec_x' : 'whsec_gateway';
        $this->app['auth']->forgetGuards();

        return $this->call('POST', "/v1/webhook/{$source}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $body, $secret),
            'HTTP_X_RAZORPAY_EVENT_ID' => $eventId ?? 'evt_'.Str::random(14),
        ], $body);
    }

    protected function capturedPayment(string $paymentId, int $amount, int $fee = 1234, int $tax = 188): array
    {
        return [
            'id' => $paymentId, 'entity' => 'payment', 'amount' => $amount, 'currency' => 'INR',
            'status' => 'captured', 'method' => 'upi', 'fee' => $fee, 'tax' => $tax, 'notes' => [],
            'created_at' => now()->timestamp,
        ];
    }

    protected function qrCredited(string $qrId, array $payment): array
    {
        return [
            'payment' => ['entity' => $payment],
            'qr_code' => ['entity' => [
                'id' => $qrId, 'entity' => 'qr_code', 'status' => 'active',
                'payments_amount_received' => $payment['amount'], 'payments_count_received' => 1,
            ]],
        ];
    }

    protected function latestPayment(): RegistrationPayment
    {
        return RegistrationPayment::latest('id')->firstOrFail();
    }

    /**
     * Happy path used by wallet/refund/dispute/collection tests: a ₹500
     * player-bears auction, one player joins, pays ₹523 via a qr_code.credited
     * webhook (fee ₹12.34 incl. ₹1.88 tax) → organiser net ₹500.66 (50 066).
     * Caller must have called fakeRazorpay() first.
     */
    protected function paidRegistration(string $ownerToken, string $paymentId = 'pay_TEST1'): RegistrationPayment
    {
        $auction = $this->createAuction($ownerToken);
        $this->setRegistration($ownerToken, $auction['id'], ['registrationMode' => 'razorpay', 'registrationFee' => 50_000, 'feeBearer' => 'player']);
        $invite = $this->createInvite($ownerToken, $auction['id']);
        $this->joinAsPlayer($auction['id'], $invite)->assertCreated();

        $payment = $this->latestPayment();
        $this->webhook('razorpay', 'qr_code.credited', $this->qrCredited($payment->razorpay_qr_id, $this->capturedPayment($paymentId, 52_300)))->assertOk();

        return $payment->refresh();
    }
}
