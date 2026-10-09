<?php

namespace Tests\Feature\Payments;

use App\Models\ApiCall;
use App\Models\ApiLimit;
use App\Services\Payments\RazorpayClient;
use App\Services\Payments\RazorpayException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RazorpayClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'payments.razorpay.key_id' => 'rzp_test_key',
            'payments.razorpay.key_secret' => 'rzp_test_secret',
            'payments.razorpayx.key_id' => 'rzp_test_key',
            'payments.razorpayx.key_secret' => 'rzp_test_secret',
            'payments.razorpayx.account_number' => '2323230000000000',
        ]);
        Http::preventStrayRequests();
    }

    public function test_create_qr_posts_with_basic_auth_and_is_audited(): void
    {
        Http::fake(['*/payments/qr_codes' => Http::response(['id' => 'qr_1', 'image_url' => 'https://rzp.io/i/qr_1', 'status' => 'active', 'close_by' => 1_900_000_000])]);

        $qr = app(RazorpayClient::class)->createQr([
            'type' => 'upi_qr', 'usage' => 'single_use', 'fixed_amount' => true,
            'payment_amount' => 52_300, 'close_by' => 1_900_000_000, 'notes' => ['payment_id' => '9'],
        ]);

        $this->assertSame('qr_1', $qr['id']);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://api.razorpay.com/v1/payments/qr_codes'
            && $r->method() === 'POST'
            && $r->hasHeader('Authorization', 'Basic '.base64_encode('rzp_test_key:rzp_test_secret'))
            && $r['payment_amount'] === 52_300);
        $this->assertDatabaseHas('api_calls', ['channel' => 'razorpay', 'message' => 'Create UPI QR', 'identifier' => '9', 'response_status' => 200]);
    }

    public function test_gateway_error_throws_razorpay_exception_with_status(): void
    {
        Http::fake(['*/payments/*/refund' => Http::response(['error' => ['description' => 'The amount is invalid']], 400)]);

        try {
            app(RazorpayClient::class)->refund('pay_1', 100);
            $this->fail('Expected RazorpayException');
        } catch (RazorpayException $e) {
            $this->assertSame(400, $e->httpStatus);
            $this->assertTrue($e->definitelyRejected());
        }
    }

    public function test_server_error_is_not_definitely_rejected(): void
    {
        Http::fake(['*/payouts' => Http::response(['error' => ['description' => 'oops']], 503)]);

        try {
            app(RazorpayClient::class)->createPayout(['amount' => 100, 'reference_id' => 'payout_1'], 'payout_1');
            $this->fail('Expected RazorpayException');
        } catch (RazorpayException $e) {
            $this->assertSame(503, $e->httpStatus);
            $this->assertFalse($e->definitelyRejected());
        }
    }

    public function test_bank_account_number_is_masked_in_the_audit_log(): void
    {
        Http::fake(['*/fund_accounts' => Http::response([
            'id' => 'fa_1', 'account_type' => 'bank_account',
            'bank_account' => ['name' => 'Raj', 'ifsc' => 'HDFC0000123', 'account_number' => '123456789012'],
        ])]);

        $fa = app(RazorpayClient::class)->createFundAccount([
            'contact_id' => 'cont_1', 'account_type' => 'bank_account',
            'bank_account' => ['name' => 'Raj', 'ifsc' => 'HDFC0000123', 'account_number' => '123456789012'],
        ]);

        $this->assertSame('fa_1', $fa['id']);
        $call = ApiCall::where('channel', 'razorpayx')->latest('id')->first();
        $this->assertStringNotContainsString('123456789012', $call->request);
        $this->assertStringNotContainsString('123456789012', (string) $call->response);
        $this->assertStringContainsString('XXXX9012', $call->request);
    }

    public function test_payout_sends_idempotency_header_and_account_number(): void
    {
        Http::fake(['*/payouts' => Http::response(['id' => 'pout_1', 'status' => 'processing'])]);

        app(RazorpayClient::class)->createPayout(['fund_account_id' => 'fa_1', 'amount' => 9410, 'reference_id' => 'payout_7'], 'payout_7');

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/v1/payouts')
            && $r->hasHeader('X-Payout-Idempotency', 'payout_7'));
    }

    public function test_fund_account_validation_uses_the_razorpayx_account_number(): void
    {
        Http::fake(['*/fund_accounts/validations' => Http::response(['id' => 'fav_1', 'status' => 'created'])]);

        app(RazorpayClient::class)->validateFundAccount('fa_1');

        Http::assertSent(fn (HttpRequest $r) => $r['account_number'] === '2323230000000000'
            && $r['fund_account'] === ['id' => 'fa_1'] && $r['amount'] === 100 && $r['currency'] === 'INR');
    }

    public function test_missing_credentials_throw_before_any_http_call(): void
    {
        config(['payments.razorpay.key_id' => null]);
        Http::fake();

        try {
            app(RazorpayClient::class)->qrPayments('qr_1');
            $this->fail('Expected RazorpayException');
        } catch (RazorpayException $e) {
            $this->assertTrue($e->notSent);
            $this->assertTrue($e->definitelyRejected());
        }
        Http::assertNothingSent();
    }

    public function test_refund_rejects_a_zero_or_negative_amount_instead_of_refunding_everything(): void
    {
        Http::fake();

        foreach ([0, -100] as $amount) {
            try {
                app(RazorpayClient::class)->refund('pay_1', $amount);
                $this->fail('Expected InvalidArgumentException');
            } catch (\InvalidArgumentException) {
                // expected
            }
        }
        Http::assertNothingSent();
    }

    public function test_refund_always_sends_the_amount_and_omits_empty_notes(): void
    {
        Http::fake(['*/payments/*/refund' => Http::response(['id' => 'rfnd_1', 'status' => 'processed'])]);

        app(RazorpayClient::class)->refund('pay_1', 100);

        Http::assertSent(fn (HttpRequest $r) => $r['amount'] === 100 && ! array_key_exists('notes', $r->data()));
    }

    public function test_call_blocked_by_the_api_gateway_is_flagged_not_sent(): void
    {
        ApiLimit::create(['channel' => 'razorpay', 'is_enabled' => false]);
        Http::fake();

        try {
            app(RazorpayClient::class)->createQr(['notes' => ['payment_id' => '1']]);
            $this->fail('Expected RazorpayException');
        } catch (RazorpayException $e) {
            $this->assertTrue($e->notSent);
            $this->assertNull($e->httpStatus);
            $this->assertTrue($e->definitelyRejected());
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('api_calls', 0);
    }

    public function test_timeout_is_neither_not_sent_nor_definitely_rejected(): void
    {
        Http::fake(['*/payouts' => fn () => throw new ConnectionException('timed out')]);

        try {
            app(RazorpayClient::class)->createPayout(['amount' => 100, 'reference_id' => 'payout_1'], 'payout_1');
            $this->fail('Expected RazorpayException');
        } catch (RazorpayException $e) {
            $this->assertFalse($e->notSent);
            $this->assertFalse($e->definitelyRejected());
        }
    }
}
