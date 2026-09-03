<?php

namespace Tests\Feature;

use App\Models\AuctionPlayer;
use App\Models\Currency;
use App\Models\OverlayTemplate;
use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlayerLibraryTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        OverlayTemplate::create(['code' => 'nakshatra', 'name' => 'Nakshatra']);
        Currency::create(['id' => 1, 'code' => 'INR', 'name' => 'Indian Rupee', 'symbol' => '₹']);

        $this->owner = User::factory()->create(['email' => 'organiser@example.com', 'password' => bcrypt('Passw0rd1')]);
        $this->token = $this->postJson('/v1/auth/login-password', [
            'email' => 'organiser@example.com', 'password' => 'Passw0rd1',
        ])->json('token');
    }

    private function asOrganiser()
    {
        return $this->withHeader('Authorization', "Bearer {$this->token}");
    }

    public function test_owner_can_create_and_list_library_players(): void
    {
        $this->asOrganiser()->postJson('/v1/players', ['name' => 'Virat K', 'role' => 'batter', 'city' => 'Delhi'])
            ->assertCreated()->assertJsonPath('player.name', 'Virat K');

        $response = $this->asOrganiser()->getJson('/v1/players');
        $response->assertOk()->assertJsonCount(1, 'players');
    }

    public function test_search_and_role_filter_narrow_the_list(): void
    {
        $this->asOrganiser()->postJson('/v1/players', ['name' => 'Rohit S', 'role' => 'batter']);
        $this->asOrganiser()->postJson('/v1/players', ['name' => 'Bumrah J', 'role' => 'bowler']);

        $this->asOrganiser()->getJson('/v1/players?search=Rohit')->assertJsonCount(1, 'players');
        $this->asOrganiser()->getJson('/v1/players?role=bowler')->assertJsonCount(1, 'players')
            ->assertJsonPath('players.0.name', 'Bumrah J');
    }

    public function test_owner_can_update_and_soft_delete_a_player(): void
    {
        $player = $this->asOrganiser()->postJson('/v1/players', ['name' => 'Player A', 'role' => 'batter'])->json('player');

        $this->asOrganiser()->patchJson("/v1/players/{$player['id']}", ['role' => 'bowler'])
            ->assertOk()->assertJsonPath('player.role', 'bowler');

        $this->asOrganiser()->deleteJson("/v1/players/{$player['id']}")->assertOk();
        $this->assertSoftDeleted('players', ['id' => $player['id']]);
    }

    public function test_history_reports_this_players_auction_appearances(): void
    {
        $auction = $this->asOrganiser()->postJson('/v1/auctions/quick-start', ['name' => 'History Auction'])->json('auction');
        $team = $this->asOrganiser()->postJson("/v1/auctions/{$auction['id']}/teams", ['name' => 'Team A', 'shortName' => 'TMA'])->json('team');

        $player = Player::create(['id_owner' => $this->owner->id, 'name' => 'History Player']);
        AuctionPlayer::create([
            'id_auction' => $auction['id'], 'id_player' => $player->id,
            'status' => AuctionPlayer::STATUS_SOLD, 'id_sold_to_team' => $team['id'], 'sold_price' => 750_000,
        ]);

        $response = $this->asOrganiser()->getJson("/v1/players/{$player->id}/history");
        $response->assertOk()
            ->assertJsonCount(1, 'history')
            ->assertJsonPath('history.0.auctionName', 'History Auction')
            ->assertJsonPath('history.0.soldPrice', 750_000);
    }

    public function test_non_owner_cannot_update_someone_elses_player(): void
    {
        $player = Player::create(['id_owner' => $this->owner->id, 'name' => 'Owned Player']);

        User::factory()->create(['email' => 'intruder@example.com', 'password' => bcrypt('Passw0rd1')]);
        $intruderToken = $this->postJson('/v1/auth/login-password', [
            'email' => 'intruder@example.com', 'password' => 'Passw0rd1',
        ])->json('token');

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$intruderToken}")
            ->patchJson("/v1/players/{$player->id}", ['role' => 'bowler'])
            ->assertStatus(403);
    }
}
