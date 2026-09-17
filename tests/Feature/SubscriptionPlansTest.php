<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /v1/subscription/plans — the team-pack catalogue the web build renders on
 * its subscription screen (it has no billing SDK to read the store's offering).
 */
class SubscriptionPlansTest extends TestCase
{
    use RefreshDatabase;

    private function token(): string
    {
        User::factory()->create(['email' => 'organiser@example.com', 'password' => bcrypt('Passw0rd1')]);

        return $this->postJson('/v1/auth/login-password', [
            'email' => 'organiser@example.com', 'password' => 'Passw0rd1',
        ])->json('token');
    }

    public function test_plans_require_authentication(): void
    {
        $this->getJson('/v1/subscription/plans')->assertUnauthorized();
    }

    public function test_plans_are_returned_ascending_by_team_count(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token())
            ->getJson('/v1/subscription/plans')
            ->assertOk()
            ->assertJsonPath('freeTeams', (int) config('subscription.free_teams'));

        $plans = $response->json('data');

        $this->assertCount(7, $plans);
        $this->assertSame([4, 6, 8, 12, 16, 20, 30], array_column($plans, 'teams'));
        $this->assertSame('4_teams', $plans[0]['productId']);
        $this->assertSame('30_teams', $plans[6]['productId']);
    }

    public function test_an_unpriced_pack_is_served_without_a_price_string(): void
    {
        // No TEAM_PACK_PRICE_* configured — the store stays authoritative and the
        // web renders the tier without a figure rather than inventing one.
        config(['subscription.team_packs' => ['12_teams' => ['teams' => 12, 'price' => null]]]);

        $plan = $this->withHeader('Authorization', 'Bearer '.$this->token())
            ->getJson('/v1/subscription/plans')
            ->assertOk()
            ->json('data.0');

        $this->assertNull($plan['price']);
        $this->assertNull($plan['priceString']);
    }

    public function test_a_priced_pack_is_formatted_with_the_configured_symbol(): void
    {
        config([
            'subscription.currency_symbol' => '₹',
            'subscription.team_packs' => [
                '12_teams' => ['teams' => 12, 'price' => 1499],
                '4_teams' => ['teams' => 4, 'price' => 499.5],
            ],
        ]);

        $plans = $this->withHeader('Authorization', 'Bearer '.$this->token())
            ->getJson('/v1/subscription/plans')
            ->assertOk()
            ->json('data');

        // Ascending by teams, so the 4-team pack sorts first despite its key order.
        $this->assertSame('₹499.50', $plans[0]['priceString']);
        $this->assertSame('₹1,499', $plans[1]['priceString']);
    }

    public function test_max_teams_resolves_from_the_pack_shape(): void
    {
        $subs = app(\App\Services\SubscriptionService::class);

        config(['subscription.team_packs' => ['30_teams' => ['teams' => 30, 'price' => null]]]);
        $this->assertSame(30, $subs->resolveMaxTeams('30_teams'));

        // A bare int is still honoured, so an older/overridden config keeps working.
        config(['subscription.team_packs' => ['16_teams' => 16]]);
        $this->assertSame(16, $subs->resolveMaxTeams('16_teams'));

        // Unmapped ids fall back to the leading number in the id.
        $this->assertSame(8, $subs->resolveMaxTeams('8_teams'));
    }
}
