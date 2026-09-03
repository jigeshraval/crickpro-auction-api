<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\OverlayTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicAuctionStateTest extends TestCase
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

        $this->auction = $this->api()->postJson('/v1/auctions/quick-start', [
            'name' => 'Public State Test Auction',
            'squadMax' => 2,
        ])->json('auction');
    }

    private function api()
    {
        return $this->withHeader('Authorization', "Bearer {$this->token}");
    }

    public function test_public_state_reflects_the_live_snapshot_without_auth(): void
    {
        $team = $this->api()->postJson("/v1/auctions/{$this->auction['id']}/teams", ['name' => 'Team Alpha', 'shortName' => 'ALP'])->json('team');
        $this->api()->postJson("/v1/auctions/{$this->auction['id']}/players", ['name' => 'Public State Player', 'categoryCode' => 'A']);
        $this->api()->postJson("/v1/auctions/{$this->auction['id']}/start");
        $this->api()->postJson("/v1/auctions/{$this->auction['id']}/control/next-player");

        $response = $this->getJson("/v1/public/auctions/{$this->auction['accessCode']}/state");

        $response->assertOk()
            ->assertJsonPath('state.auction.status', 'live')
            ->assertJsonPath('state.currentPlayer.status', 'selected')
            ->assertJsonPath('state.currentPlayer.player.name', 'Public State Player')
            ->assertJsonPath('state.teams.0.id', $team['id']);
    }

    public function test_public_state_rejects_an_unknown_code(): void
    {
        $this->getJson('/v1/public/auctions/00000-00000/state')->assertStatus(422);
    }
}
