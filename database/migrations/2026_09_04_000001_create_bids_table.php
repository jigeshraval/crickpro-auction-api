<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The append-only bid-by-bid history — one row per accepted bid. Deliberately
 * separate from auction_players.current_bid (the fast-read "what's the
 * number right now"); this table is the ledger undo() and a future audit
 * trail read from. Ported from the Node app's `bids` table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_auction')->constrained('auctions')->cascadeOnDelete();
            $table->foreignId('id_auction_player')->constrained('auction_players')->cascadeOnDelete();
            $table->foreignId('id_team')->constrained('teams')->cascadeOnDelete();
            $table->bigInteger('bid_amount');
            // Per-player monotonic sequence — the unique index below is the
            // storage-layer guarantee against a duplicate/out-of-order bid
            // slipping through a race between two rapid control actions.
            $table->unsignedInteger('sequence_number');
            $table->boolean('is_retracted')->default(false);
            $table->unsignedBigInteger('id_placed_by_user')->nullable();
            $table->timestamps(3);

            $table->unique(['id_auction_player', 'sequence_number']);
            $table->index('id_team');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bids');
    }
};
