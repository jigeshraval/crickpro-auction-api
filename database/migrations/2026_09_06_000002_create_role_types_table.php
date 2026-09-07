<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Player role types (Batter / Bowler / Wicket Keeper / All Rounder) — a small
 * reference table so the set banner + overlay can read a name/short without a
 * client-side map, and roles can be added without a migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 40);
            $table->string('short', 8);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        DB::table('role_types')->insert([
            ['id' => 1, 'name' => 'Batter', 'short' => 'BAT', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Bowler', 'short' => 'BOWL', 'sort_order' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'name' => 'Wicket Keeper', 'short' => 'WK', 'sort_order' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 4, 'name' => 'All Rounder', 'short' => 'AR', 'sort_order' => 4, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('role_types');
    }
};
