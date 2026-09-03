<?php

namespace Tests\Feature;

use App\Models\AuctionPlayer;
use App\Models\Currency;
use App\Models\OverlayTemplate;
use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamTest extends TestCase
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

        $this->auction = $this->asOrganiser()->postJson('/v1/auctions/quick-start', ['name' => 'Team Test Auction'])->json('auction');
    }

    private function asOrganiser()
    {
        return $this->withHeader('Authorization', "Bearer {$this->token}");
    }

    public function test_owner_can_create_a_team_with_server_assigned_defaults(): void
    {
        $response = $this->asOrganiser()->postJson("/v1/auctions/{$this->auction['id']}/teams", [
            'name' => 'Mumbai Marauders', 'shortName' => 'mum',
        ]);

        $response->assertCreated()
            ->assertJsonPath('team.name', 'Mumbai Marauders')
            ->assertJsonPath('team.shortName', 'MUM')
            ->assertJsonPath('team.shortcutKey', '1')
            ->assertJsonPath('team.initialPurse', 100_000_000)
            ->assertJsonPath('team.remainingPurse', 100_000_000);

        $this->assertDatabaseHas('teams', ['id_auction' => $this->auction['id'], 'short_name' => 'MUM']);
    }

    public function test_second_team_gets_a_different_shortcut_key_and_color(): void
    {
        $first = $this->asOrganiser()->postJson("/v1/auctions/{$this->auction['id']}/teams", ['name' => 'Team One', 'shortName' => 'ONE'])->json('team');
        $second = $this->asOrganiser()->postJson("/v1/auctions/{$this->auction['id']}/teams", ['name' => 'Team Two', 'shortName' => 'TWO'])->json('team');

        $this->assertSame('2', $second['shortcutKey']);
        $this->assertNotSame($first['primaryColor'], $second['primaryColor']);
    }

    public function test_owner_can_update_and_delete_a_team(): void
    {
        $team = $this->asOrganiser()->postJson("/v1/auctions/{$this->auction['id']}/teams", ['name' => 'Old Name', 'shortName' => 'OLD'])->json('team');

        $this->asOrganiser()->patchJson("/v1/auctions/{$this->auction['id']}/teams/{$team['id']}", ['name' => 'New Name'])
            ->assertOk()->assertJsonPath('team.name', 'New Name');

        $this->asOrganiser()->deleteJson("/v1/auctions/{$this->auction['id']}/teams/{$team['id']}")->assertOk();
        $this->assertDatabaseMissing('teams', ['id' => $team['id']]);
    }

    public function test_cannot_delete_a_team_that_has_bought_players(): void
    {
        $team = $this->asOrganiser()->postJson("/v1/auctions/{$this->auction['id']}/teams", ['name' => 'Buyers FC', 'shortName' => 'BUY'])->json('team');

        $player = Player::create(['id_owner' => User::first()->id, 'name' => 'Sold Player']);
        AuctionPlayer::create([
            'id_auction' => $this->auction['id'], 'id_player' => $player->id,
            'status' => AuctionPlayer::STATUS_SOLD, 'id_sold_to_team' => $team['id'], 'sold_price' => 500_000,
        ]);

        $this->asOrganiser()->deleteJson("/v1/auctions/{$this->auction['id']}/teams/{$team['id']}")->assertStatus(422);
        $this->assertDatabaseHas('teams', ['id' => $team['id']]);
    }

    public function test_non_owner_cannot_manage_teams(): void
    {
        User::factory()->create(['email' => 'intruder@example.com', 'password' => bcrypt('Passw0rd1')]);
        $intruderToken = $this->postJson('/v1/auth/login-password', [
            'email' => 'intruder@example.com', 'password' => 'Passw0rd1',
        ])->json('token');

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$intruderToken}")
            ->postJson("/v1/auctions/{$this->auction['id']}/teams", ['name' => 'Intruder FC', 'shortName' => 'INT'])
            ->assertStatus(403);
    }
}
