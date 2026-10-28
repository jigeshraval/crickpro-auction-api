<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The `turnstile:auction_auth` gate on the auth routes: enforced for the web
 * client only, verified against the shared Turnstile widget. Uses send-email-otp
 * as a representative enforced route — its SendEmailOtpRequest 422s on an empty
 * body once the gate passes, so a passing request never reaches the mail send.
 */
class TurnstileAuthGateTest extends TestCase
{
    private const URL = '/v1/auth/send-email-otp';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.turnstile.secret' => 'ts_secret', 'services.turnstile.hostnames' => 'auctions.crick.pro']);
    }

    private function fakeSiteverify(array $result, int $status = 200): void
    {
        Http::fake(['challenges.cloudflare.com/*' => Http::response($result, $status)]);
    }

    public function test_web_request_without_token_is_blocked_before_cloudflare(): void
    {
        Http::fake();

        $this->withHeaders(['platform' => 'web'])
            ->postJson(self::URL, ['email' => 'a@b.com'])
            ->assertForbidden()
            ->assertJsonPath('code', 'captcha_failed');

        Http::assertNothingSent();
    }

    public function test_web_request_with_valid_token_passes_the_gate(): void
    {
        $this->fakeSiteverify(['success' => true, 'action' => 'auction_auth', 'hostname' => 'auctions.crick.pro']);

        // Empty body: once the gate passes, SendEmailOtpRequest validation 422s
        // (email required) — proving the request got past the gate to the
        // controller rather than being 403'd.
        $this->withHeaders(['platform' => 'web', 'cf-turnstile-response' => 'tok_ok'])
            ->postJson(self::URL, [])
            ->assertStatus(422);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'turnstile/v0/siteverify') && $r['response'] === 'tok_ok');
    }

    public function test_web_request_with_wrong_action_is_blocked(): void
    {
        $this->fakeSiteverify(['success' => true, 'action' => 'player_registration', 'hostname' => 'auctions.crick.pro']);

        $this->withHeaders(['platform' => 'web', 'cf-turnstile-response' => 'tok'])
            ->postJson(self::URL, ['email' => 'a@b.com'])
            ->assertForbidden()
            ->assertJsonPath('code', 'captcha_failed');
    }

    public function test_non_web_client_skips_turnstile(): void
    {
        Http::fake();

        // Native app (platform: ios), no token, empty body → gate skipped, so the
        // FormRequest validation runs (422) instead of a captcha 403.
        $this->withHeaders(['platform' => 'ios'])
            ->postJson(self::URL, [])
            ->assertStatus(422);

        Http::assertNothingSent();
    }
}
