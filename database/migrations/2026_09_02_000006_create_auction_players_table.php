<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The join between a player and one auction — and the row that carries that
 * player's whole bidding lifecycle for this auction (status, current bid,
 * who's leading, who it sold to). The append-only bid-by-bid history itself
 * is a separate `bids` table, out of scope for this pass (live bidding).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auction_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_auction')->constrained('auctions')->cascadeOnDelete();
            $table->foreignId('id_player')->constrained('players')->restrictOnDelete();
            $table->bigInteger('base_price')->default(0);
            $table->string('category_code', 24)->nullable();
            $table->unsignedInteger('auction_order')->default(0);
            $table->json('custom_field_values')->nullable();
            $table->unsignedInteger('round_number')->default(1);
            $table->boolean('is_overseas')->default(false);
            $table->enum('status', ['pending', 'selected', 'bidding', 'sold', 'unsold', 'withdrawn'])->default('pending');
            $table->bigInteger('current_bid')->nullable();
            $table->foreignId('id_leading_team')->nullable()->constrained('teams')->nullOnDelete();
            $table->foreignId('id_sold_to_team')->nullable()->constrained('teams')->nullOnDelete();
            $table->bigInteger('sold_price')->nullable();
            $table->unsignedInteger('bid_count')->default(0);
            $table->timestamp('nominated_at')->nullable();
            $table->timestamp('sold_at')->nullable();
            $table->timestamp('unsold_at')->nullable();
            $table->timestamps();

            $table->unique(['id_auction', 'id_player']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auction_players');
    }
};
