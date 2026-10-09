<?php

namespace Tests\Feature;

use App\Models\Auction;
use App\Models\Currency;
use App\Models\OverlayTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuctionSpectatorTest extends TestCase
{
    use RefreshDatabase;

    private string $organiserToken;

    private string $viewerToken;

    private array $auction;

    protected function setUp(): void
    {
        parent::setUp();

        OverlayTemplate::create(['code' => 'nakshatra', 'name' => 'Nakshatra']);
        Currency::create(['id' => 1, 'code' => 'INR', 'name' => 'Indian Rupee', 'symbol' => '₹']);

        $this->organiserToken = $this->tokenFor('organiser@example.com');
        $this->viewerToken = $this->tokenFor('viewer@example.com');

        $this->auction = $this->as($this->organiserToken)->postJson('/v1/auctions/quick-start', [
            'name' => 'Spectator Test Auction',
            'purse' => 10_000_00,
            'squadMax' => 2,
            'minBasePrice' => 100_00,
            'bidIncrementFlat' => 10_00,
        ])->json('auction');
    }

    private function tokenFor(string $email): string
    {
        User::factory()->create(['email' => $email, 'password' => bcrypt('Passw0rd1')]);
        $this->app['auth']->forgetGuards();

        return $this->postJson('/v1/auth/login-password', ['email' => $email, 'password' => 'Passw0rd1'])->json('token');
    }

    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function guest(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withoutHeader('Authorization');
    }

    public function test_follow_is_idempotent_and_keeps_an_accurate_count(): void
    {
        $id = $this->auction['id'];

        $this->as($this->viewerToken)->postJson("/v1/auctions/{$id}/follow")
            ->assertOk()->assertJsonPath('isFollowing', true)->assertJsonPath('followersCount', 1);
        $this->as($this->viewerToken)->postJson("/v1/auctions/{$id}/follow")
            ->assertOk()->assertJsonPath('followersCount', 1);

        $this->assertDatabaseCount('auction_followers', 1);

        $this->as($this->viewerToken)->deleteJson("/v1/auctions/{$id}/follow")
            ->assertOk()->assertJsonPath('isFollowing', false)->assertJsonPath('followersCount', 0);
        $this->as($this->viewerToken)->deleteJson("/v1/auctions/{$id}/follow")
            ->assertOk()->assertJsonPath('followersCount', 0);

        $this->assertDatabaseHas('auctions', ['id' => $id, 'followers_count' => 0]);
    }

    public function test_follow_requires_auth(): void
    {
        $this->guest()->postJson("/v1/auctions/{$this->auction['id']}/follow")->assertUnauthorized();
    }

    public function test_me_following_lists_followed_auctions_newest_first(): void
    {
        $second = $this->as($this->organiserToken)->postJson('/v1/auctions/quick-start', ['name' => 'Second Auction'])->json('auction');

        $this->as($this->viewerToken)->postJson("/v1/auctions/{$this->auction['id']}/follow");
        $this->travel(1)->minutes();
        $this->as($this->viewerToken)->postJson("/v1/auctions/{$second['id']}/follow");

        $this->as($this->viewerToken)->getJson('/v1/me/following')
            ->assertOk()
            ->assertJsonPath('currentPage', 1)
            ->assertJsonPath('nextPage', null)
            ->assertJsonCount(2, 'auctions')
            ->assertJsonPath('auctions.0.id', $second['id'])
            ->assertJsonPath('auctions.0.followersCount', 1)
            ->assertJsonPath('auctions.0.teamsCount', 0)
            ->assertJsonPath('auctions.1.id', $this->auction['id']);
    }

    public function test_follow_404s_for_an_abandoned_auction(): void
    {
        Auction::whereKey($this->auction['id'])->update(['status' => Auction::STATUS_ABANDONED]);

        $this->as($this->viewerToken)->postJson("/v1/auctions/{$this->auction['id']}/follow")->assertNotFound();
    }

    public function test_follow_does_not_touch_the_auctions_updated_at(): void
    {
        $before = Auction::find($this->auction['id'])->updated_at;
        $this->travel(5)->minutes();

        $this->as($this->viewerToken)->postJson("/v1/auctions/{$this->auction['id']}/follow")->assertOk();

        $this->assertEquals($before, Auction::find($this->auction['id'])->updated_at);
    }

    public function test_me_following_hides_abandoned_auctions(): void
    {
        $this->as($this->viewerToken)->postJson("/v1/auctions/{$this->auction['id']}/follow");
        Auction::whereKey($this->auction['id'])->update(['status' => Auction::STATUS_ABANDONED]);

        $this->as($this->viewerToken)->getJson('/v1/me/following')->assertOk()->assertJsonCount(0, 'auctions');
    }

    /** @return array{0: array, 1: array} [teamA, teamB] */
    private function seedRoom(int $players = 1): array
    {
        $id = $this->auction['id'];
        $api = fn () => $this->as($this->organiserToken);

        $teamA = $api()->postJson("/v1/auctions/{$id}/teams", ['name' => 'Team Alpha', 'shortName' => 'ALP'])->json('team');
        $teamB = $api()->postJson("/v1/auctions/{$id}/teams", ['name' => 'Team Beta', 'shortName' => 'BET'])->json('team');
        for ($i = 1; $i <= $players; $i++) {
            $api()->postJson("/v1/auctions/{$id}/players", ['name' => "Player {$i}", 'categoryCode' => 'A']);
        }
        $api()->postJson("/v1/auctions/{$id}/start")->assertOk();

        return [$teamA, $teamB];
    }

    public function test_view_live_returns_the_overlay_state_for_a_guest(): void
    {
        [$teamA] = $this->seedRoom();
        $id = $this->auction['id'];
        $this->as($this->organiserToken)->postJson("/v1/auctions/{$id}/control/next-player")->assertOk();

        $this->guest()->getJson("/v1/auctions/{$id}/view/live")
            ->assertOk()
            ->assertJsonPath('data.auction.id', $id)
            ->assertJsonPath('data.auction.status', 'live')
            ->assertJsonPath('data.currentPlayer.name', 'Player 1')
            ->assertJsonPath('data.currentPlayer.status', 'selected')
            ->assertJsonPath('data.lastPlayer', null)
            ->assertJsonPath('data.teams.0.id', $teamA['id'])
            ->assertJsonPath('data.teams.0.initialPurse', 10_000_00)
            ->assertJsonPath('data.teams.0.highestBuy', null)
            ->assertJsonPath('data.settings.squadMax', 2)
            ->assertJsonPath('data.isFollowing', false)
            ->assertJsonPath('data.followersCount', 0);
    }

    public function test_view_live_reports_is_following_for_an_authed_follower(): void
    {
        $id = $this->auction['id'];
        $this->as($this->viewerToken)->postJson("/v1/auctions/{$id}/follow")->assertOk();

        $this->as($this->viewerToken)->getJson("/v1/auctions/{$id}/view/live")
            ->assertOk()
            ->assertJsonPath('data.isFollowing', true);
    }

    public function test_view_live_serves_unlisted_auctions_by_id(): void
    {
        $id = $this->auction['id'];
        $this->as($this->organiserToken)->patchJson("/v1/auctions/{$id}", ['isListed' => false])->assertOk();

        $this->guest()->getJson("/v1/auctions/{$id}/view/live")->assertOk()->assertJsonPath('data.auction.id', $id);
    }

    public function test_last_player_is_the_most_recent_sold_or_unsold_result(): void
    {
        [$teamA] = $this->seedRoom(players: 2);
        $id = $this->auction['id'];
        $api = fn () => $this->as($this->organiserToken);

        $api()->postJson("/v1/auctions/{$id}/control/next-player")->assertOk();
        $api()->postJson("/v1/auctions/{$id}/control/open-bidding")->assertOk();
        $api()->postJson("/v1/auctions/{$id}/control/bid", ['teamId' => $teamA['id']])->assertOk();
        $api()->postJson("/v1/auctions/{$id}/control/sold")->assertOk();

        $this->guest()->getJson("/v1/auctions/{$id}/view/live")
            ->assertJsonPath('data.lastPlayer.name', 'Player 1')
            ->assertJsonPath('data.lastPlayer.status', 'sold')
            ->assertJsonPath('data.lastPlayer.teamId', $teamA['id'])
            ->assertJsonPath('data.lastPlayer.price', 50_000)
            ->assertJsonPath('data.teams.0.boughtCount', 1)
            ->assertJsonPath('data.teams.0.highestBuy', 50_000);

        $this->travel(1)->minutes();
        \Illuminate\Support\Facades\Cache::flush(); // bypass the 2s view cache between snapshots

        $api()->postJson("/v1/auctions/{$id}/control/next-player")->assertOk();
        $api()->postJson("/v1/auctions/{$id}/control/open-bidding")->assertOk();
        $api()->postJson("/v1/auctions/{$id}/control/unsold")->assertOk();

        $this->guest()->getJson("/v1/auctions/{$id}/view/live")
            ->assertJsonPath('data.lastPlayer.name', 'Player 2')
            ->assertJsonPath('data.lastPlayer.status', 'unsold')
            ->assertJsonPath('data.lastPlayer.price', null);
    }

    public function test_view_live_404s_for_an_abandoned_auction(): void
    {
        Auction::whereKey($this->auction['id'])->update(['status' => Auction::STATUS_ABANDONED]);

        $this->guest()->getJson("/v1/auctions/{$this->auction['id']}/view/live")->assertNotFound();
    }

    public function test_view_live_404s_for_an_unknown_auction(): void
    {
        $this->guest()->getJson('/v1/auctions/999999/view/live')->assertNotFound();
    }
}
