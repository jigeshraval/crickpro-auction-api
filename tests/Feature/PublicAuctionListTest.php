<?php

namespace Tests\Feature;

use App\Models\Auction;
use App\Models\Currency;
use App\Models\OverlayTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicAuctionListTest extends TestCase
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

    private function make(string $name, string $status, ?string $scheduledAt = null, bool $listed = true): int
    {
        $id = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/v1/auctions/quick-start', ['name' => $name])->json('auction.id');
        Auction::whereKey($id)->update(['status' => $status, 'scheduled_at' => $scheduledAt, 'is_listed' => $listed]);

        return $id;
    }

    private function list(string $status)
    {
        return $this->withHeader('Authorization', "Bearer {$this->token}")->getJson("/v1/auctions/public?status={$status}");
    }

    public function test_live_tab_lists_live_and_paused_including_the_callers_own(): void
    {
        $live = $this->make('Live One', 'live');
        $paused = $this->make('Paused One', 'paused');
        $this->make('Done One', 'completed');
        $this->make('Hidden Live', 'live', listed: false);
        $this->make('Gone', 'abandoned');

        $ids = collect($this->list('live')->assertOk()->json('auctions'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$live, $paused], $ids);
    }

    public function test_upcoming_tab_is_scheduled_ready_or_draft_soonest_first(): void
    {
        $later = $this->make('Later', 'ready', '2026-12-01 10:00:00');
        $sooner = $this->make('Sooner', 'draft', '2026-11-01 10:00:00');
        $this->make('Undated Draft', 'draft');

        $this->list('upcoming')->assertOk()
            ->assertJsonCount(2, 'auctions')
            ->assertJsonPath('auctions.0.id', $sooner)
            ->assertJsonPath('auctions.1.id', $later);
    }

    public function test_past_tab_is_completed_only_with_standard_pagination_keys(): void
    {
        $done = $this->make('Done', 'completed');
        $this->make('Live', 'live');

        $this->list('past')->assertOk()
            ->assertJsonPath('currentPage', 1)
            ->assertJsonPath('nextPage', null)
            ->assertJsonCount(1, 'auctions')
            ->assertJsonPath('auctions.0.id', $done)
            ->assertJsonPath('auctions.0.status', 'completed')
            ->assertJsonPath('auctions.0.followersCount', 0)
            ->assertJsonPath('auctions.0.teamsCount', 0);
    }

    public function test_unknown_status_is_rejected(): void
    {
        $this->list('bogus')->assertStatus(422);
    }
}
