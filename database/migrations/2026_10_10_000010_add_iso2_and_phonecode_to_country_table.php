<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ISO2 + dial code on the local copy of crickpro-api-v2's `country` table, so a
 * Cloudflare / app country code (e.g. "IN") resolves straight to `id_country`.
 * Filled by GeoSeeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('country', function (Blueprint $table) {
            $table->char('iso2', 2)->nullable()->after('name')->index();
            $table->string('phonecode', 16)->nullable()->after('iso2');
        });
    }

    public function down(): void
    {
        Schema::table('country', function (Blueprint $table) {
            $table->dropIndex(['iso2']);
            $table->dropColumn(['iso2', 'phonecode']);
        });
    }
};
