<?php

namespace Tests\Feature;

use App\Models\Auction;
use App\Models\Currency;
use App\Models\OverlayTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuctionSettingsTest extends TestCase
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

        $this->auction = $this->asOrganiser()->postJson('/v1/auctions/quick-start', ['name' => 'Settings Test Auction'])->json('auction');
    }

    private function asOrganiser()
    {
        return $this->withHeader('Authorization', "Bearer {$this->token}");
    }

    public function test_owner_can_read_and_update_settings(): void
    {
        $this->asOrganiser()->getJson("/v1/auctions/{$this->auction['id']}/settings")
            ->assertOk()->assertJsonPath('settings.squadMax', 15);

        $response = $this->asOrganiser()->putJson("/v1/auctions/{$this->auction['id']}/settings", [
            'squadMax' => 12,
            'minBasePrice' => 20_000,
            'playerFields' => [['key' => 'jersey_size', 'label' => 'Jersey Size', 'options' => ['S', 'M', 'L']]],
        ]);

        $response->assertOk()
            ->assertJsonPath('settings.squadMax', 12)
            ->assertJsonPath('settings.minBasePrice', 20_000)
            ->assertJsonPath('settings.playerFields.0.key', 'jersey_size');

        $this->assertDatabaseHas('auction_settings', ['id_auction' => $this->auction['id'], 'squad_max' => 12]);
    }

    public function test_settings_are_locked_while_the_auction_is_live(): void
    {
        Auction::where('id', $this->auction['id'])->update(['status' => Auction::STATUS_LIVE]);

        $this->asOrganiser()->putJson("/v1/auctions/{$this->auction['id']}/settings", ['squadMax' => 12])
            ->assertStatus(422);

        $this->assertDatabaseHas('auction_settings', ['id_auction' => $this->auction['id'], 'squad_max' => 15]);
    }

    public function test_non_owner_cannot_read_settings(): void
    {
        User::factory()->create(['email' => 'intruder@example.com', 'password' => bcrypt('Passw0rd1')]);
        $intruderToken = $this->postJson('/v1/auth/login-password', [
            'email' => 'intruder@example.com', 'password' => 'Passw0rd1',
        ])->json('token');

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$intruderToken}")
            ->getJson("/v1/auctions/{$this->auction['id']}/settings")
            ->assertStatus(403);
    }
}
