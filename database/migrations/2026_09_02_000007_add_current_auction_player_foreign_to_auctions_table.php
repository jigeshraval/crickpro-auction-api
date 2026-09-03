<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deferred FK from auctions.id_current_auction_player -> auction_players.id —
 * couldn't be added in create_auctions_table since auction_players didn't
 * exist yet (the two tables reference each other).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auctions', function (Blueprint $table) {
            $table->foreign('id_current_auction_player')
                ->references('id')->on('auction_players')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('auctions', function (Blueprint $table) {
            $table->dropForeign(['id_current_auction_player']);
        });
    }
};
