<?php

namespace Tests\Feature;

use App\Models\Auction;
use App\Models\City;
use App\Models\Country;
use App\Models\CrickproLink;
use App\Models\Currency;
use App\Models\OverlayTemplate;
use App\Models\State;
use App\Models\User;
use App\Services\AuctionLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AuctionLocationTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        OverlayTemplate::create(['code' => 'nakshatra', 'name' => 'Nakshatra']);
        Currency::create(['id' => 1, 'code' => 'INR', 'name' => 'Indian Rupee', 'symbol' => '₹']);

        Country::create(['id' => 2, 'name' => 'India']);
        Country::create(['id' => 39, 'name' => 'Canada']);
        State::create(['id' => 10, 'name' => 'Gujarat', 'id_country' => 2]);
        State::create(['id' => 20, 'name' => 'Alberta', 'id_country' => 39]);
        City::create(['id' => 100, 'name' => 'Ahmedabad', 'id_state' => 10, 'id_country' => 2]);
        City::create(['id' => 200, 'name' => 'Calgary', 'id_state' => 20, 'id_country' => 39]);

        User::factory()->create(['email' => 'organiser@example.com', 'password' => bcrypt('Passw0rd1')]);
        $this->token = $this->postJson('/v1/auth/login-password', [
            'email' => 'organiser@example.com', 'password' => 'Passw0rd1',
        ])->json('token');
    }

    private function authed()
    {
        return $this->withHeader('Authorization', "Bearer {$this->token}");
    }

    public function test_quick_start_with_city_sets_ids_and_location(): void
    {
        $this->authed()->postJson('/v1/auctions/quick-start', ['name' => 'Calgary Cup', 'cityId' => 200])
            ->assertCreated()
            ->assertJsonPath('auction.cityId', 200)
            ->assertJsonPath('auction.stateId', 20)
            ->assertJsonPath('auction.countryId', 39)
            ->assertJsonPath('auction.location', 'Calgary, Alberta, Canada');

        $this->assertDatabaseHas('auctions', [
            'name' => 'Calgary Cup', 'id_city' => 200, 'id_state' => 20, 'id_country' => 39, 'location' => 'Calgary, Alberta, Canada',
        ]);
    }

    public function test_quick_start_without_city_leaves_location_empty(): void
    {
        $this->authed()->postJson('/v1/auctions/quick-start', ['name' => 'No City Cup'])
            ->assertCreated()
            ->assertJsonPath('auction.cityId', null)
            ->assertJsonPath('auction.location', null);
    }

    public function test_invalid_city_is_rejected(): void
    {
        $this->authed()->postJson('/v1/auctions/quick-start', ['name' => 'Bad City Cup', 'cityId' => 999999])
            ->assertStatus(422)->assertJsonValidationErrors('cityId');

        $id = $this->authed()->postJson('/v1/auctions/quick-start', ['name' => 'Good Cup'])->json('auction.id');
        $this->authed()->patchJson("/v1/auctions/{$id}", ['cityId' => 999999])
            ->assertStatus(422)->assertJsonValidationErrors('cityId');
    }

    public function test_update_changes_and_clears_the_city(): void
    {
        $id = $this->authed()->postJson('/v1/auctions/quick-start', ['name' => 'Moving Cup', 'cityId' => 100])->json('auction.id');

        $this->authed()->patchJson("/v1/auctions/{$id}", ['cityId' => 200])
            ->assertOk()
            ->assertJsonPath('auction.cityId', 200)
            ->assertJsonPath('auction.stateId', 20)
            ->assertJsonPath('auction.countryId', 39)
            ->assertJsonPath('auction.location', 'Calgary, Alberta, Canada');

        // Other fields leave the location alone.
        $this->authed()->patchJson("/v1/auctions/{$id}", ['venue' => 'Shaw Park'])
            ->assertOk()->assertJsonPath('auction.location', 'Calgary, Alberta, Canada');

        $this->authed()->patchJson("/v1/auctions/{$id}", ['cityId' => null])
            ->assertOk()
            ->assertJsonPath('auction.cityId', null)
            ->assertJsonPath('auction.stateId', null)
            ->assertJsonPath('auction.countryId', null)
            ->assertJsonPath('auction.location', null);
    }

    public function test_location_formatting_skips_missing_parts(): void
    {
        $this->assertSame('Calgary, Alberta, Canada', AuctionLocation::format('Calgary', 'Alberta', 'Canada'));
        $this->assertSame('Calgary, Canada', AuctionLocation::format('Calgary', '', 'Canada'));
        $this->assertSame('Alberta, Canada', AuctionLocation::format(null, 'Alberta', 'Canada'));
        $this->assertSame('India', AuctionLocation::format(null, '  ', 'India'));
        $this->assertNull(AuctionLocation::format(null, null, null));

        // A state without a city keeps state + country (country derived from the state).
        $auction = Auction::create([
            'id_owner' => User::first()->id, 'id_currency' => 1, 'name' => 'State Only', 'slug' => 'state-only',
            'access_code' => '12345-678', 'status' => Auction::STATUS_READY, 'id_state' => 10,
        ]);
        $this->assertNull($auction->id_city);
        $this->assertSame(2, (int) $auction->id_country);
        $this->assertSame('Gujarat, India', $auction->location);
    }

    public function test_provision_copies_the_tournament_city(): void
    {
        config(['services.crickpro.url' => 'https://v2.test', 'services.crickpro.provision_secret' => 'shh']);
        Http::fake([
            'v2.test/api/user/auction-identity' => Http::response(['user' => ['id' => 501, 'name' => 'Organiser', 'mobile' => '+919999900001']]),
            'v2.test/api/v2/auction-import/tournament/77/teams' => Http::response([
                'teams' => [],
                'tournament' => ['id' => 77, 'name' => 'Calgary League', 'cityId' => 200, 'stateId' => 20, 'countryId' => 39],
            ]),
        ]);

        $id = $this->withHeader('X-Crickpro-Signature', 'shh')
            ->postJson('/v1/integrations/crickpro/provision', ['token' => 't', 'tournamentId' => 77, 'tournamentName' => 'Calgary League'])
            ->assertCreated()->json('auctionId');

        $this->assertDatabaseHas('auctions', [
            'id' => $id, 'id_crickpro_series' => 77, 'id_city' => 200, 'id_state' => 20, 'id_country' => 39, 'location' => 'Calgary, Alberta, Canada',
        ]);
    }

    public function test_provision_city_in_the_payload_wins(): void
    {
        config(['services.crickpro.url' => 'https://v2.test', 'services.crickpro.provision_secret' => 'shh']);
        Http::fake([
            'v2.test/api/user/auction-identity' => Http::response(['user' => ['id' => 502, 'name' => 'Organiser', 'mobile' => '+919999900002']]),
            'v2.test/api/v2/auction-import/tournament/78/teams' => Http::response(['teams' => [], 'tournament' => ['id' => 78, 'cityId' => 200]]),
        ]);

        $id = $this->withHeader('X-Crickpro-Signature', 'shh')
            ->postJson('/v1/integrations/crickpro/provision', ['token' => 't', 'tournamentId' => 78, 'tournamentName' => 'Gujarat League', 'cityId' => 100])
            ->assertCreated()->json('auctionId');

        $this->assertSame('Ahmedabad, Gujarat, India', Auction::find($id)->location);
    }

    public function test_team_import_from_a_tournament_fills_a_blank_location(): void
    {
        config(['services.crickpro.url' => 'https://v2.test']);
        Http::fake([
            'v2.test/api/v2/auction-import/tournament/79/teams' => Http::response(['teams' => [], 'tournament' => ['id' => 79, 'cityId' => null, 'stateId' => 20, 'countryId' => 39]]),
        ]);
        CrickproLink::create(['id_owner' => User::first()->id, 'crickpro_user_id' => 503, 'name' => 'Organiser', 'token' => 'link-token']);

        $id = $this->authed()->postJson('/v1/auctions/quick-start', ['name' => 'From Tournament'])->json('auction.id');
        $this->authed()->postJson("/v1/auctions/{$id}/teams/import-crickpro", ['tournamentId' => 79, 'teams' => [['name' => 'Stampede XI']]])
            ->assertOk();

        // Tournament with a state/country but no city keeps what it has.
        $auction = Auction::find($id);
        $this->assertNull($auction->id_city);
        $this->assertSame(20, (int) $auction->id_state);
        $this->assertSame('Alberta, Canada', $auction->location);
    }
}
