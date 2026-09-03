<?php

namespace Tests\Feature;

use App\Models\AuctionPlayer;
use App\Models\Currency;
use App\Models\OverlayTemplate;
use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuctionPlayerTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private array $auction;

    protected function setUp(): void
    {
        parent::setUp();

        OverlayTemplate::create(['code' => 'nakshatra', 'name' => 'Nakshatra']);
        Currency::create(['id' => 1, 'code' => 'INR', 'name' => 'Indian Rupee', 'symbol' => '₹']);

        User::factory()->create(['email' => 'organiser@example.com', 'password' => bcrypt('Passw0rd1')]);
        $this->token = $this->postJson('/v1/auth/login-password', [
            'email' => 'organiser@example.com', 'password' => 'Passw0rd1',
        ])->json('token');

        $this->auction = $this->asOrganiser()->postJson('/v1/auctions/quick-start', ['name' => 'Roster Test Auction'])->json('auction');
    }

    private function asOrganiser()
    {
        return $this->withHeader('Authorization', "Bearer {$this->token}");
    }

    public function test_adding_a_player_creates_both_the_library_row_and_the_roster_row(): void
    {
        $response = $this->asOrganiser()->postJson("/v1/auctions/{$this->auction['id']}/players", [
            'name' => 'New Player', 'role' => 'bowler', 'categoryCode' => 'A',
        ]);

        $response->assertCreated()
            ->assertJsonPath('player.player.name', 'New Player')
            ->assertJsonPath('player.categoryCode', 'A')
            // Category A's default base price from quick-start's defaults.
            ->assertJsonPath('player.basePrice', 50_000)
            ->assertJsonPath('player.status', 'pending');

        $this->assertDatabaseHas('players', ['name' => 'New Player']);
        $this->assertDatabaseHas('auction_players', ['id_auction' => $this->auction['id'], 'category_code' => 'A']);
    }

    public function test_explicit_base_price_overrides_the_category_default(): void
    {
        $response = $this->asOrganiser()->postJson("/v1/auctions/{$this->auction['id']}/players", [
            'name' => 'Priced Player', 'categoryCode' => 'A', 'basePrice' => 999_000,
        ]);

        $response->assertJsonPath('player.basePrice', 999_000);
    }

    public function test_owner_can_update_both_identity_and_roster_fields_in_one_call(): void
    {
        $player = $this->asOrganiser()->postJson("/v1/auctions/{$this->auction['id']}/players", ['name' => 'Editable Player'])->json('player');

        $response = $this->asOrganiser()->patchJson("/v1/auctions/{$this->auction['id']}/players/{$player['id']}", [
            'name' => 'Renamed Player', 'basePrice' => 250_000, 'isOverseas' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('player.player.name', 'Renamed Player')
            ->assertJsonPath('player.basePrice', 250_000)
            ->assertJsonPath('player.isOverseas', true);
    }

    public function test_list_carries_the_whole_auction_order_so_clients_need_only_one_request(): void
    {
        $first = $this->asOrganiser()->postJson("/v1/auctions/{$this->auction['id']}/players", ['name' => 'First'])->json('player');
        $second = $this->asOrganiser()->postJson("/v1/auctions/{$this->auction['id']}/players", ['name' => 'Second'])->json('player');

        // Filtered and paginated down to nothing, allIds still describes the
        // WHOLE auction — that is what the position badge counts against.
        $response = $this->asOrganiser()->getJson("/v1/auctions/{$this->auction['id']}/players?status=sold");

        $response->assertOk()
            ->assertJsonCount(0, 'players')
            ->assertJsonPath('allIds', [$first['id'], $second['id']]);
    }

    public function test_owner_can_fetch_one_roster_row(): void
    {
        $player = $this->asOrganiser()->postJson("/v1/auctions/{$this->auction['id']}/players", ['name' => 'Detail Player'])->json('player');

        $this->asOrganiser()->getJson("/v1/auctions/{$this->auction['id']}/players/{$player['id']}")
            ->assertOk()
            ->assertJsonPath('player.id', $player['id'])
            ->assertJsonPath('player.player.name', 'Detail Player');
    }

    public function test_a_roster_row_cannot_be_read_through_someone_elses_auction(): void
    {
        $player = $this->asOrganiser()->postJson("/v1/auctions/{$this->auction['id']}/players", ['name' => 'Private Player'])->json('player');

        User::factory()->create(['email' => 'stranger@example.com', 'password' => bcrypt('Passw0rd1')]);
        $strangerToken = $this->postJson('/v1/auth/login-password', [
            'email' => 'stranger@example.com', 'password' => 'Passw0rd1',
        ])->json('token');

        // Without this the guard keeps the organiser resolved from the earlier
        // requests in this test and the intruder's token is never consulted —
        // same reason PlayerLibraryTest calls it.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$strangerToken}")
            ->getJson("/v1/auctions/{$this->auction['id']}/players/{$player['id']}")
            ->assertForbidden();
    }

    public function test_reorder_replaces_the_full_auction_order(): void
    {
        $first = $this->asOrganiser()->postJson("/v1/auctions/{$this->auction['id']}/players", ['name' => 'First'])->json('player');
        $second = $this->asOrganiser()->postJson("/v1/auctions/{$this->auction['id']}/players", ['name' => 'Second'])->json('player');

        $this->asOrganiser()->putJson("/v1/auctions/{$this->auction['id']}/players/reorder", [
            'orderedIds' => [$second['id'], $first['id']],
        ])->assertOk();

        $this->assertDatabaseHas('auction_players', ['id' => $second['id'], 'auction_order' => 0]);
        $this->assertDatabaseHas('auction_players', ['id' => $first['id'], 'auction_order' => 1]);
    }

    public function test_bulk_add_parses_pasted_lines(): void
    {
        $text = "Name One, batter, A, 100000, 9999999999\nName Two, bowler, B";

        $response = $this->asOrganiser()->postJson("/v1/auctions/{$this->auction['id']}/players/bulk", ['text' => $text]);

        $response->assertOk()->assertJsonPath('created', 2);
        $this->assertDatabaseHas('players', ['name' => 'Name One', 'role' => 'batter', 'phone' => '9999999999']);
        $this->assertDatabaseHas('auction_players', ['id_auction' => $this->auction['id'], 'category_code' => 'A', 'base_price' => 100_000]);
    }

    public function test_from_library_attaches_existing_players_and_skips_duplicates(): void
    {
        $owner = User::first();
        $libraryPlayer = Player::create(['id_owner' => $owner->id, 'name' => 'Library Player']);

        $first = $this->asOrganiser()->postJson("/v1/auctions/{$this->auction['id']}/players/from-library", [
            'playerIds' => [$libraryPlayer->id],
        ]);
        $first->assertOk()->assertJsonPath('attached', 1);

        $second = $this->asOrganiser()->postJson("/v1/auctions/{$this->auction['id']}/players/from-library", [
            'playerIds' => [$libraryPlayer->id],
        ]);
        $second->assertOk()->assertJsonPath('attached', 0);

        $this->assertDatabaseCount('auction_players', 1);
    }

    public function test_cannot_remove_a_sold_player(): void
    {
        $player = $this->asOrganiser()->postJson("/v1/auctions/{$this->auction['id']}/players", ['name' => 'Sold Player'])->json('player');

        AuctionPlayer::where('id', $player['id'])->update(['status' => 'sold']);

        $this->asOrganiser()->deleteJson("/v1/auctions/{$this->auction['id']}/players/{$player['id']}")->assertStatus(422);
        $this->assertDatabaseHas('auction_players', ['id' => $player['id']]);
    }
}
