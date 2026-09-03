<?php

namespace Database\Seeders;

use App\Models\OverlayTemplate;
use Illuminate\Database\Seeder;

/**
 * Seeds the overlay template quick-start falls back to (see
 * AuctionRepository::DEFAULT_OVERLAY_CODE) when the caller doesn't specify
 * one — without this row, a fresh install silently skips attaching an
 * overlay instead of matching the source app's default behaviour.
 */
class OverlayTemplateSeeder extends Seeder
{
    public function run(): void
    {
        OverlayTemplate::firstOrCreate(
            ['code' => 'nakshatra'],
            ['name' => 'Nakshatra', 'sort_order' => 0]
        );
    }
}
