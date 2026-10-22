<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Payments\PaymentTestHelpers;
use Tests\TestCase;

class TurnstileJoinTest extends TestCase
{
    use PaymentTestHelpers;
    use RefreshDatabase;

    private int $auctionId;

    private string $invite;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPayments();
        $owner = $this->tokenFor('organiser@example.com');
        $this->auctionId = $this->createAuction($owner)['id'];
        $this->invite = $this->createInvite($owner, $this->auctionId);
        config(['services.turnstile.secret' => 'ts_secret', 'services.turnstile.hostnames' => 'auctions.crick.pro']);
    }

    private function join(?string $token)
    {
        $payload = ['name' => 'Rahul Player', 'role' => 'batter'];
        if ($token !== null) {
            $payload['cf-turnstile-response'] = $token;
        }

        return $this->guest()->postJson("/v1/public/invitations/{$this->auctionId}/{$this->invite}/join", $payload);
    }

    private function fakeSiteverify(array $result, int $status = 200): void
    {
        Http::fake(['challenges.cloudflare.com/*' => Http::response($result, $status)]);
    }

    public function test_valid_token_registers_and_sends_secret_token_and_ip(): void
    {
        $this->fakeSiteverify(['success' => true, 'action' => 'player_registration', 'hostname' => 'auctions.crick.pro']);

        $this->join('tok_ok')->assertOk();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'turnstile/v0/siteverify')
            && $r['secret'] === 'ts_secret' && $r['response'] === 'tok_ok' && ! empty($r['remoteip']));
        $this->assertDatabaseHas('players', ['name' => 'Rahul Player']);
    }

    public function test_missing_token_is_rejected_without_calling_cloudflare(): void
    {
        Http::fake();
        $this->join(null)->assertForbidden()->assertJsonPath('code', 'captcha_failed');
        Http::assertNothingSent();
        $this->assertDatabaseMissing('players', ['name' => 'Rahul Player']);
    }

    public function test_failed_wrong_action_or_wrong_hostname_is_rejected(): void
    {
        foreach ([
            ['success' => false, 'error-codes' => ['invalid-input-response']],
            ['success' => true, 'action' => 'signup', 'hostname' => 'auctions.crick.pro'],
            ['success' => true, 'action' => 'player_registration', 'hostname' => 'evil.example.com'],
        ] as $result) {
            $this->fakeSiteverify($result);
            $this->join('tok')->assertForbidden();
        }
        $this->assertDatabaseMissing('players', ['name' => 'Rahul Player']);
    }

    public function test_cloudflare_error_fails_closed(): void
    {
        $this->fakeSiteverify(['oops' => true], 500);
        $this->join('tok')->assertForbidden();
    }

    public function test_check_is_skipped_outside_production_when_no_secret_is_configured(): void
    {
        config(['services.turnstile.secret' => '']);
        Http::fake();
        $this->join(null)->assertOk();
        Http::assertNothingSent();
    }
}
