<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Reference data only — no fake/factory rows. This runs on every production
     * deploy, and faker (fake()) is a dev-only dependency stripped by
     * `composer install --no-dev`, so anything factory-based would fatal there.
     */
    public function run(): void
    {
        $this->call(OverlayTemplateSeeder::class);
        $this->call(CurrencySeeder::class);
    }
}
