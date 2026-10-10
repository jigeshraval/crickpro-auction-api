<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Country;
use App\Models\State;
use App\Models\User;
use Database\Seeders\GeoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeoSeederTest extends TestCase
{
    use RefreshDatabase;

    private function seedGeo(): void
    {
        (new GeoSeeder(base_path('tests/Fixtures/geo')))->run();
    }

    public function test_seeds_countries_states_cities_with_source_ids_and_iso2(): void
    {
        $this->seedGeo();

        $this->assertDatabaseHas('country', ['id' => 2, 'name' => 'India', 'iso2' => 'IN', 'phonecode' => '91']);
        $this->assertDatabaseHas('state', ['id' => 10, 'name' => 'Maharashtra', 'id_country' => 2]);
        $this->assertDatabaseHas('city', ['id' => 500, 'name' => 'Mumbai', 'id_state' => 10, 'id_country' => 2]);
        // Parents missing from the source are nulled instead of breaking the FK.
        $this->assertDatabaseHas('state', ['id' => 11, 'id_country' => null]);
        $this->assertDatabaseHas('city', ['id' => 502, 'id_state' => null, 'id_country' => 2]);
    }

    public function test_is_idempotent_and_skips_when_already_seeded(): void
    {
        $this->seedGeo();
        Country::whereKey(2)->update(['name' => 'Renamed']);
        $this->seedGeo(); // counts match → skipped, local edit untouched

        $this->assertSame(2, Country::count());
        $this->assertSame(3, City::count());
        $this->assertSame(2, State::count());
        $this->assertDatabaseHas('country', ['id' => 2, 'name' => 'Renamed']);
    }

    public function test_links_existing_users_to_their_country_row(): void
    {
        $user = User::factory()->create(['country' => 'IN', 'id_country' => null]);
        $this->seedGeo();

        $this->assertSame(2, $user->fresh()->id_country);
    }
}
