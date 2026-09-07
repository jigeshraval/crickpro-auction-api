<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Source references back to CrickPro, so a completed auction can be pushed into
 * the tournament: each auction team → its CrickPro team, each CrickPro-sourced
 * player → its CrickPro player. Nullable — manually-added / registration players
 * carry no CrickPro id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->unsignedBigInteger('id_crickpro_team')->nullable()->after('id_auction')->index();
        });
        Schema::table('players', function (Blueprint $table) {
            $table->unsignedBigInteger('id_crickpro_player')->nullable()->after('id_owner')->index();
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('id_crickpro_team');
        });
        Schema::table('players', function (Blueprint $table) {
            $table->dropColumn('id_crickpro_player');
        });
    }
};
