<?php

namespace Tests\Feature;

use App\Models\AuctionPlayer;
use App\Models\AuctionSetting;
use App\Models\Currency;
use App\Models\OverlayTemplate;
use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuctionControlTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private array $auction;

    private array $teamA;

    private array $teamB;

    private array $player;

    protected function setUp(): void
    {
        parent::setUp();

        OverlayTemplate::create(['code' => 'nakshatra', 'name' => 'Nakshatra']);
        Currency::create(['id' => 1, 'code' => 'INR', 'name' => 'Indian Rupee', 'symbol' => '₹']);

        User::factory()->create(['email' => 'organiser@example.com', 'password' => bcrypt('Passw0rd1')]);
        $this->token = $this->postJson('/v1/auth/login-password', [
            'email' => 'organiser@example.com', 'password' => 'Passw0rd1',
        ])->json('token');

        $this->auction = $this->api()->postJson('/v1/auctions/quick-start', [
            'name' => 'Control Room Test Auction',
            'purse' => 10_000_00,
            'squadMax' => 2,
            'minBasePrice' => 100_00,
            'bidIncrementFlat' => 10_00,
        ])->json('auction');

        $this->teamA = $this->api()->postJson("/v1/auctions/{$this->auction['id']}/teams", ['name' => 'Team Alpha', 'shortName' => 'ALP'])->json('team');
        $this->teamB = $this->api()->postJson("/v1/auctions/{$this->auction['id']}/teams", ['name' => 'Team Beta', 'shortName' => 'BET'])->json('team');
        $this->player = $this->api()->postJson("/v1/auctions/{$this->auction['id']}/players", ['name' => 'Bid Test Player', 'categoryCode' => 'A'])->json('player');

        $this->api()->postJson("/v1/auctions/{$this->auction['id']}/start")->assertOk();
    }

    private function api()
    {
        return $this->withHeader('Authorization', "Bearer {$this->token}");
    }

    public function test_full_bidding_flow_sells_a_player(): void
    {
        $this->api()->postJson("/v1/auctions/{$this->auction['id']}/control/next-player")
            ->assertOk()
            ->assertJsonPath('state.currentPlayer.status', 'selected')
            ->assertJsonPath('state.currentPlayer.player.name', 'Bid Test Player');

        $this->api()->postJson("/v1/auctions/{$this->auction['id']}/control/open-bidding")
            ->assertOk()->assertJsonPath('state.currentPlayer.status', 'bidding');

        // Category A's default base price from quick-start's defaults (50_000), not auction_settings.min_base_price.
        $bid1 = $this->api()->postJson("/v1/auctions/{$this->auction['id']}/control/bid", ['teamId' => $this->teamA['id']]);
        $bid1->assertOk()
            ->assertJsonPath('state.currentPlayer.currentBid', 50_000)
            ->assertJsonPath('state.currentPlayer.leadingTeam.id', $this->teamA['id']);

        $bid2 = $this->api()->postJson("/v1/auctions/{$this->auction['id']}/control/bid", ['teamId' => $this->teamB['id']]);
        $bid2->assertOk()->assertJsonPath('state.currentPlayer.currentBid', 51_000);

        $sold = $this->api()->postJson("/v1/auctions/{$this->auction['id']}/control/sold");
        $sold->assertOk()
            ->assertJsonPath('state.currentPlayer', null)
            ->assertJsonPath('state.teams.1.remainingPurse', 10_000_00 - 51_000); // teamB is second in the list

        $this->assertDatabaseHas('auction_players', [
            'id' => $this->player['id'], 'status' => 'sold', 'id_sold_to_team' => $this->teamB['id'], 'sold_price' => 51_000,
        ]);
        $this->assertDatabaseCount('bids', 2);
    }

    public function test_a_team_cannot_bid_against_itself(): void
    {
        $this->api()->postJson("/v1/auctions/{$this->auction['id']}/control/next-player");
        $this->api()->postJson("/v1/auctions/{$this->auction['id']}/control/open-bidding");
        $this->api()->postJson("/v1/auctions/{$this->auction['id']}/control/bid", ['teamId' => $this->teamA['id']]);

        $response = $this->api()->postJson("/v1/auctions/{$this->auction['id']}/control/bid", ['teamId' => $this->teamA['id']]);
        $response->assertStatus(422)->assertJsonPath('code', 'INVALID_TEAM');
    }

    public function test_bidding_rejects_a_team_with_a_full_squad(): void
    {
        // Fill teamA's squad to its max (2) by directly marking two other players sold to it.
        AuctionSetting::where('id_auction', $this->auction['id'])->update(['squad_max' => 1]);
        $owner = User::first();
        $filler = Player::create(['id_owner' => $owner->id, 'name' => 'Filler Player']);
        AuctionPlayer::create([
            'id_auction' => $this->auction['id'], 'id_player' => $filler->id,
            'status' => 'sold', 'id_sold_to_team' => $this->teamA['id'], 'sold_price' => 100_00,
        ]);

        $this->api()->postJson("/v1/auctions/{$this->auction['id']}/control/next-player");
        $this->api()->postJson("/v1/auctions/{$this->auction['id']}/control/open-bidding");

        $response = $this->api()->postJson("/v1/auctions/{$this->auction['id']}/control/bid", ['teamId' => $this->teamA['id']]);
        $response->assertStatus(422)->assertJsonPath('code', 'SQUAD_FULL');
    }

    public function test_undo_reverts_the_last_bid(): void
    {
        $this->api()->postJson("/v1/auctions/{$this->auction['id']}/control/next-player");
        $this->api()->postJson("/v1/auctions/{$this->auction['id']}/control/open-bidding");
        $this->api()->postJson("/v1/auctions/{$this->auction['id']}/control/bid", ['teamId' => $this->teamA['id']]);
        $this->api()->postJson("/v1/auctions/{$this->auction['id']}/control/bid", ['teamId' => $this->teamB['id']]);

        $undo = $this->api()->postJson("/v1/auctions/{$this->auction['id']}/control/undo");
        $undo->assertOk()
            ->assertJsonPath('state.currentPlayer.currentBid', 50_000)
            ->assertJsonPath('state.currentPlayer.leadingTeam.id', $this->teamA['id']);

        $this->assertDatabaseCount('bids', 1);
    }

    public function test_unsold_then_return_to_pool_resets_the_player(): void
    {
        $this->api()->postJson("/v1/auctions/{$this->auction['id']}/control/next-player");
        $this->api()->postJson("/v1/auctions/{$this->auction['id']}/control/open-bidding");
        $this->api()->postJson("/v1/auctions/{$this->auction['id']}/control/unsold")
            ->assertOk()->assertJsonPath('state.currentPlayer', null);

        $this->assertDatabaseHas('auction_players', ['id' => $this->player['id'], 'status' => 'unsold']);

        $this->api()->postJson("/v1/auctions/{$this->auction['id']}/control/return-to-pool", ['auctionPlayerId' => $this->player['id']])
            ->assertOk();
        $this->assertDatabaseHas('auction_players', ['id' => $this->player['id'], 'status' => 'pending']);
    }

    public function test_sold_refunds_on_return_to_pool(): void
    {
        $this->api()->postJson("/v1/auctions/{$this->auction['id']}/control/next-player");
        $this->api()->postJson("/v1/auctions/{$this->auction['id']}/control/open-bidding");
        $this->api()->postJson("/v1/auctions/{$this->auction['id']}/control/bid", ['teamId' => $this->teamA['id']]);
        $this->api()->postJson("/v1/auctions/{$this->auction['id']}/control/sold");

        $this->assertDatabaseHas('teams', ['id' => $this->teamA['id'], 'remaining_purse' => 10_000_00 - 50_000]);

        $this->api()->postJson("/v1/auctions/{$this->auction['id']}/control/return-to-pool", ['auctionPlayerId' => $this->player['id']])->assertOk();

        $this->assertDatabaseHas('teams', ['id' => $this->teamA['id'], 'remaining_purse' => 10_000_00]);
    }

    public function test_state_endpoint_reflects_current_snapshot(): void
    {
        $this->api()->postJson("/v1/auctions/{$this->auction['id']}/control/next-player");

        $response = $this->api()->getJson("/v1/auctions/{$this->auction['id']}/state");
        $response->assertOk()
            ->assertJsonPath('state.auction.status', 'live')
            ->assertJsonPath('state.currentPlayer.status', 'selected')
            ->assertJsonCount(2, 'state.teams');
    }

    public function test_non_owner_cannot_control_the_auction(): void
    {
        User::factory()->create(['email' => 'intruder@example.com', 'password' => bcrypt('Passw0rd1')]);
        $intruderToken = $this->postJson('/v1/auth/login-password', [
            'email' => 'intruder@example.com', 'password' => 'Passw0rd1',
        ])->json('token');
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$intruderToken}")
            ->postJson("/v1/auctions/{$this->auction['id']}/control/next-player")
            ->assertStatus(403);
    }
}
