<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Normalise the free-text `role` enum into a role_types FK. Keeps `role` around
 * (backwards-compatible) and backfills `id_role_type` from it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->foreignId('id_role_type')->nullable()->after('role')->constrained('role_types')->nullOnDelete();
        });

        // Map the old enum → role_types ids (wk_batter folds into Wicket Keeper).
        $map = ['batter' => 1, 'bowler' => 2, 'wicket_keeper' => 3, 'wk_batter' => 3, 'all_rounder' => 4];
        foreach ($map as $role => $id) {
            DB::table('players')->where('role', $role)->update(['id_role_type' => $id]);
        }
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropConstrainedForeignId('id_role_type');
        });
    }
};
