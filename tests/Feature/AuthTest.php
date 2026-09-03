<?php

namespace Tests\Feature;

use App\Mail\OtpMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_check_user_succeeds_for_a_real_domain(): void
    {
        // Regression test: email:rfc,dns previously failed here even for
        // example.com (a real, always-resolvable domain) because
        // egulias/email-validator's DNSCheckValidation is stricter than raw
        // checkdnsrr() — CheckUserRequest now uses email:rfc only. The
        // throttle test below only ever asserted the FINAL 429 and never
        // checked that the prior 30 requests actually succeeded, so this
        // gap went undetected until manual browser verification caught it.
        $response = $this->postJson('/v1/auth/check-user', ['email' => 'regressioncheck@example.com']);
        $response->assertOk()->assertJsonPath('userExists', false);
    }

    public function test_register_with_email_creates_user_and_returns_token(): void
    {
        $response = $this->postJson('/v1/auth/register', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'Passw0rd!',
            'countryCode' => 'IN',
            'termsAccepted' => '1',
        ]);

        $response->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('user.email', 'jane@example.com')
            ->assertJsonStructure(['token']);

        $this->assertDatabaseHas('users', ['email' => 'jane@example.com', 'verified' => true]);
    }

    public function test_register_with_mobile_creates_user(): void
    {
        $response = $this->postJson('/v1/auth/register', [
            'name' => 'John Smith',
            'mobile' => '9876543210',
            'phoneCode' => '91',
            'password' => 'Passw0rd!',
            'countryCode' => 'IN',
            'termsAccepted' => '1',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('users', ['mobile' => '+919876543210']);
    }

    public function test_register_rejects_weak_password(): void
    {
        $response = $this->postJson('/v1/auth/register', [
            'name' => 'Jane Doe',
            'email' => 'jane2@example.com',
            'password' => 'weakpass',
            'countryCode' => 'IN',
            'termsAccepted' => '1',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('users', ['email' => 'jane2@example.com']);
    }

    public function test_register_rejects_missing_terms_acceptance(): void
    {
        $response = $this->postJson('/v1/auth/register', [
            'name' => 'Jane Doe',
            'email' => 'jane3@example.com',
            'password' => 'Passw0rd1',
            'countryCode' => 'IN',
            'termsAccepted' => '0',
        ]);

        $response->assertStatus(422);
    }

    public function test_register_claims_a_placeholder_account(): void
    {
        $placeholder = User::factory()->create([
            'email' => 'placeholder@example.com',
            'password' => null,
            'verified' => false,
        ]);

        $response = $this->postJson('/v1/auth/register', [
            'name' => 'Real Name',
            'email' => 'placeholder@example.com',
            'password' => 'Passw0rd1',
            'countryCode' => 'IN',
            'termsAccepted' => '1',
        ]);

        $response->assertCreated();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', ['id' => $placeholder->id, 'name' => 'Real Name', 'verified' => true]);
    }

    public function test_login_with_correct_password_succeeds(): void
    {
        User::factory()->create(['email' => 'login@example.com', 'password' => bcrypt('Passw0rd1')]);

        $response = $this->postJson('/v1/auth/login-password', [
            'email' => 'login@example.com',
            'password' => 'Passw0rd1',
        ]);

        $response->assertOk()->assertJsonStructure(['token', 'user' => ['id', 'email']]);
    }

    public function test_login_with_wrong_password_fails(): void
    {
        User::factory()->create(['email' => 'login2@example.com', 'password' => bcrypt('Passw0rd1')]);

        $response = $this->postJson('/v1/auth/login-password', [
            'email' => 'login2@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(400);
    }

    public function test_email_otp_send_and_verify_logs_in_existing_user(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'otp@example.com']);

        $send = $this->postJson('/v1/auth/send-email-otp', ['email' => 'otp@example.com']);
        $send->assertOk()->assertJsonStructure(['debugOtp']);
        $otp = $send->json('debugOtp');

        Mail::assertSent(OtpMail::class);

        $verify = $this->postJson('/v1/auth/verify-email-otp', ['email' => 'otp@example.com', 'otp' => $otp]);
        $verify->assertOk()->assertJsonStructure(['token', 'user']);
    }

    public function test_email_otp_verify_rejects_wrong_code(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'otp2@example.com']);

        $this->postJson('/v1/auth/send-email-otp', ['email' => 'otp2@example.com']);

        $verify = $this->postJson('/v1/auth/verify-email-otp', ['email' => 'otp2@example.com', 'otp' => '000000']);
        $verify->assertStatus(422);
    }

    public function test_email_otp_verify_reports_new_user_for_registration(): void
    {
        Mail::fake();

        $send = $this->postJson('/v1/auth/send-email-otp', ['email' => 'newperson@example.com']);
        $otp = $send->json('debugOtp');

        $verify = $this->postJson('/v1/auth/verify-email-otp', ['email' => 'newperson@example.com', 'otp' => $otp]);
        $verify->assertOk()->assertJsonPath('userExists', false);
    }

    public function test_whatsapp_otp_send_and_verify_for_registration(): void
    {
        $send = $this->postJson('/v1/auth/send-whatsapp-otp', ['mobile' => '9876500000', 'phoneCode' => '91']);
        $send->assertOk()->assertJsonStructure(['debugOtp']);
        $otp = $send->json('debugOtp');

        $verify = $this->postJson('/v1/auth/verify-whatsapp-otp', [
            'mobile' => '9876500000', 'phoneCode' => '91', 'otp' => $otp,
        ]);
        $verify->assertOk()->assertJsonPath('status', 'success');

        // The code is consumed by verify-whatsapp-otp — reusing it must fail.
        $reuse = $this->postJson('/v1/auth/verify-whatsapp-otp', [
            'mobile' => '9876500000', 'phoneCode' => '91', 'otp' => $otp,
        ]);
        $reuse->assertStatus(422);
    }

    public function test_forgot_password_mobile_flow_resets_and_logs_in(): void
    {
        User::factory()->create(['mobile' => '+919876511111', 'password' => bcrypt('OldPassw0rd')]);

        $send = $this->postJson('/v1/auth/send-whatsapp-otp', ['mobile' => '9876511111', 'phoneCode' => '91']);
        $otp = $send->json('debugOtp');

        // Pre-check step does not consume the code.
        $preCheck = $this->postJson('/v1/auth/verify-reset-otp-mobile', [
            'mobile' => '9876511111', 'phoneCode' => '91', 'otp' => $otp,
        ]);
        $preCheck->assertOk();

        $reset = $this->postJson('/v1/auth/reset-password-mobile', [
            'mobile' => '9876511111', 'phoneCode' => '91', 'otp' => $otp, 'password' => 'NewPassw0rd1',
        ]);
        $reset->assertOk()->assertJsonStructure(['token']);

        $login = $this->postJson('/v1/auth/login-password-mobile', [
            'mobile' => '9876511111', 'phoneCode' => '91', 'password' => 'NewPassw0rd1',
        ]);
        $login->assertOk();
    }

    public function test_forgot_password_email_flow_resets_and_logs_in(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'forgot@example.com', 'password' => bcrypt('OldPassw0rd')]);

        $send = $this->postJson('/v1/auth/send-email-otp', ['email' => 'forgot@example.com']);
        $otp = $send->json('debugOtp');

        $reset = $this->postJson('/v1/auth/reset-password', [
            'email' => 'forgot@example.com',
            'otp' => $otp,
            'password' => 'NewPassw0rd1',
            'password_confirmation' => 'NewPassw0rd1',
        ]);
        $reset->assertOk()->assertJsonStructure(['token']);

        $login = $this->postJson('/v1/auth/login-password', [
            'email' => 'forgot@example.com', 'password' => 'NewPassw0rd1',
        ]);
        $login->assertOk();
    }

    public function test_authenticated_user_endpoint_requires_token(): void
    {
        $this->getJson('/v1/auth/user')->assertStatus(401);
    }

    public function test_me_and_logout(): void
    {
        $user = User::factory()->create(['email' => 'me@example.com', 'password' => bcrypt('Passw0rd1')]);
        $login = $this->postJson('/v1/auth/login-password', ['email' => 'me@example.com', 'password' => 'Passw0rd1']);
        $token = $login->json('token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/v1/auth/user')
            ->assertOk()
            ->assertJsonPath('user.email', 'me@example.com');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/auth/logout')
            ->assertOk();

        // Sanctum's RequestGuard caches the resolved user on itself for the
        // lifetime of the app container, which in a feature test persists
        // across separate $this->getJson() calls within one test method —
        // without this, the next call would return the pre-logout cached
        // user instead of re-resolving the (now-deleted) token. Confirmed
        // via real HTTP requests against the running dev server that a
        // deleted token correctly 401s outside of this test-harness quirk.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/v1/auth/user')
            ->assertStatus(401);
    }

    public function test_single_session_per_client_revokes_prior_token(): void
    {
        User::factory()->create(['email' => 'session@example.com', 'password' => bcrypt('Passw0rd1')]);

        $first = $this->withHeader('X-CrickPro-Client', 'crickpro-auction-app')
            ->postJson('/v1/auth/login-password', ['email' => 'session@example.com', 'password' => 'Passw0rd1'])
            ->json('token');

        // First token works before the second login.
        $this->withHeader('Authorization', "Bearer {$first}")->getJson('/v1/auth/user')->assertOk();

        $this->app['auth']->forgetGuards();

        $second = $this->withHeader('X-CrickPro-Client', 'crickpro-auction-app')
            ->postJson('/v1/auth/login-password', ['email' => 'session@example.com', 'password' => 'Passw0rd1'])
            ->json('token');

        $this->app['auth']->forgetGuards();

        // First token is now revoked; second still works.
        $this->withHeader('Authorization', "Bearer {$first}")->getJson('/v1/auth/user')->assertStatus(401);

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$second}")->getJson('/v1/auth/user')->assertOk();
    }

    public function test_different_clients_do_not_revoke_each_other(): void
    {
        User::factory()->create(['email' => 'multi@example.com', 'password' => bcrypt('Passw0rd1')]);

        $appToken = $this->withHeader('X-CrickPro-Client', 'crickpro-auction-app')
            ->postJson('/v1/auth/login-password', ['email' => 'multi@example.com', 'password' => 'Passw0rd1'])
            ->json('token');

        $webToken = $this->withHeader('X-CrickPro-Client', 'crickpro-auction')
            ->postJson('/v1/auth/login-password', ['email' => 'multi@example.com', 'password' => 'Passw0rd1'])
            ->json('token');

        $this->withHeader('Authorization', "Bearer {$appToken}")->getJson('/v1/auth/user')->assertOk();

        // See the forgetGuards() note in test_single_session_per_client_revokes_prior_token —
        // without this, the next call would return the cached $appToken user
        // rather than actually re-resolving $webToken (they'd happen to look
        // identical here since both tokens belong to the same user, masking
        // the bug — reset explicitly so this test can't false-positive).
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$webToken}")->getJson('/v1/auth/user')->assertOk();
    }

    public function test_check_user_throttle_trips_429(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->postJson('/v1/auth/check-user', ['email' => 'throttle@example.com']);
        }

        $response = $this->postJson('/v1/auth/check-user', ['email' => 'throttle@example.com']);
        $response->assertStatus(429);
    }
}
