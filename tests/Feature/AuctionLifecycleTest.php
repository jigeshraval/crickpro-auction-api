<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\OverlayTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuctionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        OverlayTemplate::create(['code' => 'nakshatra', 'name' => 'Nakshatra']);
        Currency::create(['id' => 1, 'code' => 'INR', 'name' => 'Indian Rupee', 'symbol' => '₹']);

        User::factory()->create(['email' => 'organiser@example.com', 'password' => bcrypt('Passw0rd1')]);
        $this->token = $this->postJson('/v1/auth/login-password', [
            'email' => 'organiser@example.com', 'password' => 'Passw0rd1',
        ])->json('token');
    }

    private function api()
    {
        return $this->withHeader('Authorization', "Bearer {$this->token}");
    }

    private function newAuction(): array
    {
        return $this->api()->postJson('/v1/auctions/quick-start', ['name' => 'Lifecycle Test Auction'])->json('auction');
    }

    public function test_start_pause_resume_complete_walks_the_full_lifecycle(): void
    {
        $auction = $this->newAuction();

        $this->api()->postJson("/v1/auctions/{$auction['id']}/start")->assertOk()->assertJsonPath('auction.status', 'live');
        $this->api()->postJson("/v1/auctions/{$auction['id']}/pause")->assertOk()->assertJsonPath('auction.status', 'paused');
        $this->api()->postJson("/v1/auctions/{$auction['id']}/resume")->assertOk()->assertJsonPath('auction.status', 'live');
        $this->api()->postJson("/v1/auctions/{$auction['id']}/complete")->assertOk()->assertJsonPath('auction.status', 'completed');
    }

    public function test_cannot_start_an_already_live_auction(): void
    {
        $auction = $this->newAuction();
        $this->api()->postJson("/v1/auctions/{$auction['id']}/start")->assertOk();

        $this->api()->postJson("/v1/auctions/{$auction['id']}/start")->assertStatus(422);
    }

    public function test_delete_is_blocked_while_live(): void
    {
        $auction = $this->newAuction();
        $this->api()->postJson("/v1/auctions/{$auction['id']}/start")->assertOk();

        $this->api()->deleteJson("/v1/auctions/{$auction['id']}")->assertStatus(422);
        $this->assertDatabaseHas('auctions', ['id' => $auction['id']]);
    }

    public function test_delete_succeeds_while_ready(): void
    {
        $auction = $this->newAuction();

        $this->api()->deleteJson("/v1/auctions/{$auction['id']}")->assertOk();
        $this->assertSoftDeleted('auctions', ['id' => $auction['id']]);
    }
}
