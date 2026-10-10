<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\User;
use App\Services\Payments\RegistrationPaymentsEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class UserCountryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Country::create(['id' => 101, 'name' => 'India']);
        Country::create(['id' => 231, 'name' => 'United Arab Emirates']);
    }

    private function login(array $extra = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/v1/auth/login-password', ['email' => 'org@example.com', 'password' => 'Passw0rd1', ...$extra]);
    }

    public function test_register_persists_country_and_resolves_the_country_row(): void
    {
        $this->postJson('/v1/auth/register', [
            'name' => 'Jane Doe', 'email' => 'jane@example.com', 'password' => 'Passw0rd!',
            'countryCode' => 'in', 'countryName' => 'india', 'termsAccepted' => '1',
        ])->assertCreated()
            ->assertJsonPath('user.country', 'IN')
            ->assertJsonPath('user.countryId', 101)
            ->assertJsonPath('user.features.registrationPayments', true);

        $this->assertDatabaseHas('users', ['email' => 'jane@example.com', 'country' => 'IN', 'id_country' => 101]);
    }

    public function test_unknown_country_name_still_stores_the_code(): void
    {
        $this->postJson('/v1/auth/register', [
            'name' => 'Ana Smith', 'email' => 'ana@example.com', 'password' => 'Passw0rd!',
            'countryCode' => 'XK', 'countryName' => 'Kosovo', 'termsAccepted' => '1',
        ])->assertCreated()
            ->assertJsonPath('user.country', 'XK')
            ->assertJsonPath('user.countryId', null)
            ->assertJsonPath('user.features.registrationPayments', false);
    }

    public function test_register_rejects_a_non_iso2_country_code(): void
    {
        $this->postJson('/v1/auth/register', [
            'name' => 'Jane Doe', 'email' => 'jane@example.com', 'password' => 'Passw0rd!',
            'countryCode' => 'IND', 'termsAccepted' => '1',
        ])->assertStatus(422)->assertJsonValidationErrors('countryCode');
    }

    public function test_country_code_must_be_two_ascii_letters(): void
    {
        foreach (['ÉÉ', 'ïn', 'I1'] as $code) {
            $this->postJson('/v1/auth/register', [
                'name' => 'Jane Doe', 'email' => 'jane@example.com', 'password' => 'Passw0rd!',
                'countryCode' => $code, 'termsAccepted' => '1',
            ])->assertStatus(422)->assertJsonValidationErrors('countryCode');
        }

        User::factory()->create(['email' => 'org@example.com', 'password' => bcrypt('Passw0rd1')]);
        $this->login(['countryCode' => 'ÉÉ'])->assertStatus(422)->assertJsonValidationErrors('countryCode');
    }

    public function test_login_sets_country_only_when_provided(): void
    {
        User::factory()->create(['email' => 'org@example.com', 'password' => bcrypt('Passw0rd1')]);

        $this->login()->assertOk()->assertJsonPath('user.country', null)->assertJsonPath('user.features.registrationPayments', false);
        $this->login(['countryCode' => 'ae', 'countryName' => 'United Arab Emirates'])
            ->assertOk()->assertJsonPath('user.country', 'AE')->assertJsonPath('user.countryId', 231);
        $this->login()->assertOk()->assertJsonPath('user.country', 'AE')->assertJsonPath('user.countryId', 231);
        $this->login(['countryCode' => 'IN'])->assertOk()->assertJsonPath('user.country', 'IN')->assertJsonPath('user.countryId', null);
    }

    public function test_patch_user_syncs_country_for_existing_sessions(): void
    {
        User::factory()->create(['email' => 'org@example.com', 'password' => bcrypt('Passw0rd1')]);
        $token = $this->login()->json('token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson('/v1/auth/user', ['countryCode' => 'IN', 'countryName' => 'India'])
            ->assertOk()
            ->assertJsonPath('user.country', 'IN')
            ->assertJsonPath('user.countryId', 101)
            ->assertJsonPath('user.features.registrationPayments', true);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/v1/auth/user')
            ->assertJsonPath('user.country', 'IN')
            ->assertJsonPath('user.features.registrationPayments', true);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson('/v1/auth/user', ['countryCode' => 'IND'])
            ->assertStatus(422);

        $this->app['auth']->forgetGuards();
        $this->withoutHeader('Authorization')->patchJson('/v1/auth/user', ['countryCode' => 'IN'])->assertUnauthorized();
    }

    public function test_country_is_filled_from_cloudflare_only_while_unset(): void
    {
        User::factory()->create(['email' => 'org@example.com', 'password' => bcrypt('Passw0rd1')]);
        $token = $this->login()->json('token');
        $auth = fn () => $this->withHeader('Authorization', "Bearer {$token}");

        // Cloudflare's unknown / Tor codes and a missing header set nothing.
        $auth()->withHeader('CF-IPCountry', 'XX')->getJson('/v1/auth/user')->assertJsonPath('user.country', null);
        $auth()->withHeader('CF-IPCountry', 'T1')->getJson('/v1/auth/user')->assertJsonPath('user.country', null);
        $auth()->withoutHeader('CF-IPCountry')->getJson('/v1/auth/user')->assertJsonPath('user.country', null);

        // App start (GET /auth/user) fills it from Cloudflare.
        $auth()->withHeader('CF-IPCountry', 'in')->getJson('/v1/auth/user')
            ->assertOk()->assertJsonPath('user.country', 'IN')->assertJsonPath('user.features.registrationPayments', true);

        // Once set, a different Cloudflare country never overwrites it.
        $auth()->withHeader('CF-IPCountry', 'AE')->getJson('/v1/auth/user')->assertJsonPath('user.country', 'IN');
    }

    public function test_login_without_a_country_code_fills_it_from_cloudflare(): void
    {
        User::factory()->create(['email' => 'org@example.com', 'password' => bcrypt('Passw0rd1')]);

        $this->withHeader('CF-IPCountry', 'IN')->postJson('/v1/auth/login-password', ['email' => 'org@example.com', 'password' => 'Passw0rd1'])
            ->assertOk()->assertJsonPath('user.country', 'IN');
    }

    public function test_backfill_marks_plus_91_mobiles_as_india(): void
    {
        $indian = User::factory()->create(['mobile' => '+919876543210']);
        $canadian = User::factory()->create(['mobile' => '+15875550100']);
        $alreadySet = User::factory()->create(['mobile' => '+919000000001', 'country' => 'AE']);

        $migration = require base_path('database/migrations/2026_10_10_000000_add_country_to_users_table.php');
        $migration->backfill();

        $this->assertDatabaseHas('users', ['id' => $indian->id, 'country' => 'IN', 'id_country' => 101]);
        $this->assertDatabaseHas('users', ['id' => $canadian->id, 'country' => null, 'id_country' => null]);
        $this->assertDatabaseHas('users', ['id' => $alreadySet->id, 'country' => 'AE']);
    }

    public function test_owner_eligibility_is_india_only(): void
    {
        $this->assertTrue(RegistrationPaymentsEligibility::forOwner(User::factory()->make(['country' => 'IN'])));
        $this->assertFalse(RegistrationPaymentsEligibility::forOwner(User::factory()->make(['country' => 'AE'])));
        $this->assertFalse(RegistrationPaymentsEligibility::forOwner(User::factory()->make(['country' => null])));
        $this->assertFalse(RegistrationPaymentsEligibility::forOwner(null));
    }
}
