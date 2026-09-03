<?php

namespace Tests\Feature;

use App\Models\Auction;
use App\Models\Currency;
use App\Models\OverlayTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuctionTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        OverlayTemplate::create(['code' => 'nakshatra', 'name' => 'Nakshatra']);
        Currency::create(['id' => 1, 'code' => 'INR', 'name' => 'Indian Rupee', 'symbol' => '₹']);
        Currency::create(['id' => 2, 'code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$']);

        User::factory()->create(['email' => 'organiser@example.com', 'password' => bcrypt('Passw0rd1')]);
        $this->token = $this->postJson('/v1/auth/login-password', [
            'email' => 'organiser@example.com', 'password' => 'Passw0rd1',
        ])->json('token');
    }

    private function quickStart(array $payload)
    {
        return $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/v1/auctions/quick-start', $payload);
    }

    public function test_quick_start_requires_auth(): void
    {
        $this->postJson('/v1/auctions/quick-start', ['name' => 'No Auth Auction'])->assertStatus(401);
    }

    public function test_quick_start_with_only_a_name_applies_defaults(): void
    {
        $response = $this->quickStart(['name' => 'Season Opener']);

        $response->assertCreated()
            ->assertJsonPath('auction.name', 'Season Opener')
            ->assertJsonPath('auction.status', 'ready')
            ->assertJsonPath('auction.isListed', true)
            ->assertJsonPath('auction.currency.code', 'INR')
            ->assertJsonStructure(['broadcastToken', 'slug', 'auction' => ['id', 'slug', 'accessCode', 'currency' => ['id', 'code', 'name', 'symbol']]]);

        $auction = Auction::where('name', 'Season Opener')->firstOrFail();

        $this->assertNotNull($auction->access_code);
        $this->assertDatabaseHas('auction_settings', [
            'id_auction' => $auction->id,
            'initial_purse' => 100_000_000,
            'squad_max' => 15,
            'bid_increment_mode' => 'slab',
        ]);
        $this->assertDatabaseCount('auction_categories', 3);
        $this->assertDatabaseHas('auction_categories', ['id_auction' => $auction->id, 'code' => 'A', 'default_base_price' => 50_000]);
        $this->assertDatabaseCount('teams', 0);
        $this->assertDatabaseHas('auction_overlays', ['id_auction' => $auction->id, 'is_active' => true]);
        $this->assertDatabaseHas('broadcast_sessions', ['id_auction' => $auction->id]);
    }

    public function test_quick_start_with_full_payload_creates_everything(): void
    {
        $response = $this->quickStart([
            'name' => 'Full Payload Auction',
            'purse' => 50_000_000,
            'squadMax' => 20,
            'venue' => 'City Ground',
            'scheduledAt' => '2026-12-01T10:00:00Z',
            'minBasePrice' => 100_000,
            'bidIncrementMode' => 'flat',
            'bidIncrementFlat' => 100_000,
            'teams' => [
                ['name' => 'Team One', 'shortName' => 'ONE'],
                ['name' => 'Team Two', 'shortName' => 'TWO', 'primaryColor' => '#FF0000'],
            ],
            'categories' => [
                ['code' => 'x', 'name' => 'Marquee', 'defaultBasePrice' => 200_000],
            ],
        ]);

        $response->assertCreated();
        $auction = Auction::where('name', 'Full Payload Auction')->firstOrFail();

        $this->assertSame('City Ground', $auction->venue);
        $this->assertDatabaseHas('auction_settings', [
            'id_auction' => $auction->id,
            'initial_purse' => 50_000_000,
            'squad_max' => 20,
            'min_base_price' => 100_000,
            'bid_increment_mode' => 'flat',
            'bid_increment_flat' => 100_000,
        ]);
        $this->assertDatabaseCount('auction_categories', 1);
        $this->assertDatabaseHas('auction_categories', ['id_auction' => $auction->id, 'code' => 'X', 'default_base_price' => 200_000]);
        $this->assertDatabaseCount('teams', 2);
        $this->assertDatabaseHas('teams', [
            'id_auction' => $auction->id, 'name' => 'Team Two', 'short_name' => 'TWO',
            'primary_color' => '#FF0000', 'initial_purse' => 50_000_000, 'remaining_purse' => 50_000_000,
        ]);
    }

    public function test_quick_start_honors_an_explicit_currency(): void
    {
        $usd = Currency::where('code', 'USD')->firstOrFail();

        $response = $this->quickStart(['name' => 'USD Auction', 'currencyId' => $usd->id]);

        $response->assertCreated()->assertJsonPath('auction.currency.code', 'USD');
        $auction = Auction::where('name', 'USD Auction')->firstOrFail();
        $this->assertSame($usd->id, $auction->id_currency);
    }

    public function test_quick_start_gives_duplicate_names_distinct_slugs(): void
    {
        $first = $this->quickStart(['name' => 'Rematch Cup'])->json('auction');
        $second = $this->quickStart(['name' => 'Rematch Cup'])->json('auction');

        $this->assertNotSame($first['slug'], $second['slug']);
    }

    public function test_quick_start_rejects_missing_name(): void
    {
        $this->quickStart([])->assertStatus(422);
    }

    public function test_owner_can_set_auction_private(): void
    {
        $auction = $this->quickStart(['name' => 'Visibility Test'])->json('auction');

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->patchJson("/v1/auctions/{$auction['id']}", ['isListed' => false]);

        $response->assertOk()->assertJsonPath('auction.isListed', false);
        $this->assertDatabaseHas('auctions', ['id' => $auction['id'], 'is_listed' => false]);
    }

    public function test_public_feed_includes_other_organisers_listed_auctions_but_not_private_ones_or_the_callers_own(): void
    {
        $this->quickStart(['name' => 'My Own Public Auction']);
        $private = $this->quickStart(['name' => 'Private One'])->json('auction');
        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->patchJson("/v1/auctions/{$private['id']}", ['isListed' => false]);

        User::factory()->create(['email' => 'someone-else@example.com', 'password' => bcrypt('Passw0rd1')]);
        $otherToken = $this->postJson('/v1/auth/login-password', [
            'email' => 'someone-else@example.com', 'password' => 'Passw0rd1',
        ])->json('token');
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$otherToken}")
            ->postJson('/v1/auctions/quick-start', ['name' => 'Someone Else\'s Public Auction']);
        $this->app['auth']->forgetGuards();

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")->getJson('/v1/auctions/public');

        $response->assertOk()->assertJsonCount(1, 'auctions')
            ->assertJsonPath('auctions.0.name', "Someone Else's Public Auction")
            ->assertJsonStructure(['auctions' => [['id', 'name', 'accessCode', 'currency', 'squadMax', 'initialPurse']]]);
    }

    public function test_index_lists_only_the_owners_own_auctions(): void
    {
        $this->quickStart(['name' => 'Mine One']);
        $this->quickStart(['name' => 'Mine Two']);

        User::factory()->create(['email' => 'someone-else@example.com', 'password' => bcrypt('Passw0rd1')]);
        $otherToken = $this->postJson('/v1/auth/login-password', [
            'email' => 'someone-else@example.com', 'password' => 'Passw0rd1',
        ])->json('token');
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$otherToken}")
            ->postJson('/v1/auctions/quick-start', ['name' => 'Someone Else\'s Auction']);
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/v1/auctions')
            ->assertOk()->assertJsonCount(2, 'auctions');
    }

    public function test_show_returns_a_single_owned_auction(): void
    {
        $auction = $this->quickStart(['name' => 'Detail Auction'])->json('auction');

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/v1/auctions/{$auction['id']}")
            ->assertOk()->assertJsonPath('auction.name', 'Detail Auction');
    }

    public function test_non_owner_cannot_view_auction_detail(): void
    {
        $auction = $this->quickStart(['name' => 'Private Detail Auction'])->json('auction');

        User::factory()->create(['email' => 'intruder2@example.com', 'password' => bcrypt('Passw0rd1')]);
        $intruderToken = $this->postJson('/v1/auth/login-password', [
            'email' => 'intruder2@example.com', 'password' => 'Passw0rd1',
        ])->json('token');
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$intruderToken}")
            ->getJson("/v1/auctions/{$auction['id']}")
            ->assertStatus(403);
    }

    public function test_non_owner_cannot_update_auction(): void
    {
        $auction = $this->quickStart(['name' => 'Owned By Someone Else'])->json('auction');

        User::factory()->create(['email' => 'intruder@example.com', 'password' => bcrypt('Passw0rd1')]);
        $intruderToken = $this->postJson('/v1/auth/login-password', [
            'email' => 'intruder@example.com', 'password' => 'Passw0rd1',
        ])->json('token');

        // See the forgetGuards() note in AuthTest::test_different_clients_do_not_revoke_each_other —
        // Sanctum's RequestGuard caches the resolved user for the container's
        // lifetime, so without this the PATCH below would still authenticate
        // as $this->token's owner (organiser) instead of re-resolving the
        // intruder's token, and this test would false-positive.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$intruderToken}")
            ->patchJson("/v1/auctions/{$auction['id']}", ['isListed' => false])
            ->assertStatus(403);
    }
}
