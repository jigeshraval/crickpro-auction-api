<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Match crickpro-api-v2's table names (`country`, `state`, `city`) — the geo
 * data is a copy of those tables with the same ids. Foreign keys pointing at
 * the old names (states/cities/users.id_country) follow the rename.
 */
return new class extends Migration
{
    /** old (plural) => new (singular, api-v2 naming) */
    private const TABLES = ['countries' => 'country', 'states' => 'state', 'cities' => 'city'];

    /**
     * Idempotent: renames only where the old table exists and the new one doesn't,
     * so a partially-applied earlier run (or a DB that already has the singular
     * names) re-runs cleanly.
     */
    public function up(): void
    {
        foreach (self::TABLES as $old => $new) {
            if (Schema::hasTable($old) && ! Schema::hasTable($new)) {
                Schema::rename($old, $new);
            }
        }

        // Neither name exists (geo tables never created on this DB) → create them
        // with the singular names and the same shape as the geo_location_tables migration.
        if (! Schema::hasTable('country')) {
            Schema::create('country', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100);
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('state')) {
            Schema::create('state', function (Blueprint $table) {
                $table->id();
                $table->string('name', 255);
                $table->foreignId('id_country')->nullable()->constrained('country')->nullOnDelete();
                $table->timestamps();
                $table->index(['id_country', 'name']);
            });
        }
        if (! Schema::hasTable('city')) {
            Schema::create('city', function (Blueprint $table) {
                $table->id();
                $table->string('name', 255);
                $table->foreignId('id_state')->nullable()->constrained('state')->nullOnDelete();
                $table->foreignId('id_country')->nullable()->constrained('country')->nullOnDelete();
                $table->timestamps();
                $table->index(['id_country', 'id_state', 'name']);
                if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                    $table->fullText('name');
                }
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES, true) as $old => $new) {
            if (Schema::hasTable($new) && ! Schema::hasTable($old)) {
                Schema::rename($new, $old);
            }
        }
    }
};
