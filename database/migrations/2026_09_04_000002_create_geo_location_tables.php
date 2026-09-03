<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Country/state/city, trimmed to just what the Venue city-search field
 * needs (id + name + parent ids) — a local copy of crickpro-api's own
 * `country`/`state`/`city` tables (its `GET /v3/cities`), not a live call
 * to that service. crickpro-api's tables carry a lot this app has no use
 * for (currency, timezones, translations, ...) — country/currency data for
 * this app's own auth flow already lives in the bundled Countries.ts, this
 * is purely for city search. IDs are preserved from the source data on
 * import (see the accompanying import script) so they stay meaningful.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->timestamps();
        });

        Schema::create('states', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->foreignId('id_country')->nullable()->constrained('countries')->nullOnDelete();
            $table->timestamps();

            $table->index(['id_country', 'name']);
        });

        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->foreignId('id_state')->nullable()->constrained('states')->nullOnDelete();
            $table->foreignId('id_country')->nullable()->constrained('countries')->nullOnDelete();
            $table->timestamps();

            $table->index(['id_country', 'id_state', 'name']);
            // Sqlite (the test suite's in-memory driver) has no fulltext
            // index support at all — real environments run MySQL.
            if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                $table->fullText('name');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cities');
        Schema::dropIfExists('states');
        Schema::dropIfExists('countries');
    }
};
