<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Countries / states / cities — a copy of crickpro-api-v2's `country` / `state` /
 * `city` tables with the SAME ids, loaded from the gzipped CSVs in
 * database/seeders/data/geo (regenerate them in api-v2 with
 * `php artisan geo:export-auction` and copy the files here).
 *
 * Runs from DatabaseSeeder on every deploy, so it skips itself when the tables
 * already hold the same row counts as the files. Upserts by id (idempotent);
 * parents missing from the source are nulled rather than breaking the FKs.
 * Finally links existing users to their country row via users.country (ISO2).
 */
class GeoSeeder extends Seeder
{
    private const CHUNK = 1000;

    public function __construct(private readonly ?string $dir = null) {}

    public function run(): void
    {
        $dir = $this->dir ?? database_path('seeders/data/geo');

        $countries = $this->read("{$dir}/country.csv.gz");
        $states = $this->read("{$dir}/state.csv.gz");
        $cities = $this->read("{$dir}/city.csv.gz");

        if (DB::table('country')->count() === count($countries)
            && DB::table('state')->count() === count($states)
            && DB::table('city')->count() === count($cities)) {
            $this->linkUsers();

            return;
        }

        $now = now();
        $countryIds = array_flip(array_map(fn ($r) => (int) $r['id'], $countries));
        $stateIds = array_flip(array_map(fn ($r) => (int) $r['id'], $states));

        $this->upsert('country', array_map(fn ($r) => [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'iso2' => $r['iso2'] !== '' ? strtoupper($r['iso2']) : null,
            'phonecode' => $r['phonecode'] !== '' ? $r['phonecode'] : null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $countries), ['name', 'iso2', 'phonecode', 'updated_at']);

        $this->upsert('state', array_map(fn ($r) => [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'id_country' => isset($countryIds[(int) $r['id_country']]) ? (int) $r['id_country'] : null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $states), ['name', 'id_country', 'updated_at']);

        $this->upsert('city', array_map(fn ($r) => [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'id_state' => isset($stateIds[(int) $r['id_state']]) ? (int) $r['id_state'] : null,
            'id_country' => isset($countryIds[(int) $r['id_country']]) ? (int) $r['id_country'] : null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $cities), ['name', 'id_state', 'id_country', 'updated_at']);

        $this->linkUsers();
    }

    /** @return list<array<string,string>> */
    private function read(string $path): array
    {
        $handle = gzopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("GeoSeeder: cannot open {$path}");
        }
        $header = null;
        $rows = [];
        // gzopen streams decompress transparently, so fgetcsv works on them.
        while (($line = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if ($header === null) {
                $header = $line;

                continue;
            }
            if ($line === [null]) {
                continue;
            }
            $rows[] = array_combine($header, $line);
        }
        gzclose($handle);

        return $rows;
    }

    private function upsert(string $table, array $rows, array $update): void
    {
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table($table)->upsert($chunk, ['id'], $update);
        }
    }

    /** users.country (ISO2, from the app / Cloudflare) → users.id_country. */
    private function linkUsers(): void
    {
        $map = DB::table('country')->whereNotNull('iso2')->pluck('id', 'iso2');
        DB::table('users')->whereNotNull('country')->whereNull('id_country')->orderBy('id')
            ->select('id', 'country')
            ->chunkById(1000, function ($users) use ($map) {
                foreach ($users as $u) {
                    if (isset($map[$u->country])) {
                        DB::table('users')->where('id', $u->id)->update(['id_country' => $map[$u->country]]);
                    }
                }
            });
    }
}
