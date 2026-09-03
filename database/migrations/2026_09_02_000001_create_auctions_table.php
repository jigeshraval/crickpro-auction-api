<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from crickpro-auction-api (Node)'s `auctions` table. `id_current_auction_player`
 * is added without its foreign key here — auction_players doesn't exist yet, and the two
 * tables reference each other. The FK itself is added once auction_players exists (see
 * add_current_auction_player_foreign_to_auctions_table).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auctions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_owner')->constrained('users')->restrictOnDelete();
            $table->string('name', 150);
            $table->string('slug', 160)->unique();
            $table->string('season_label', 60)->nullable();
            $table->text('description')->nullable();
            $table->string('logo_url', 500)->nullable();
            $table->string('cover_url', 500)->nullable();
            $table->string('venue', 200)->nullable();
            $table->string('access_code', 12)->nullable()->unique();
            $table->char('currency_code', 3)->default('INR');
            $table->string('currency_symbol', 8)->default('₹');
            $table->boolean('is_listed')->default(true);
            $table->enum('status', ['draft', 'ready', 'live', 'paused', 'completed', 'abandoned'])->default('draft');
            $table->unsignedInteger('current_round')->default(1);
            $table->unsignedBigInteger('id_current_auction_player')->nullable();
            $table->unsignedBigInteger('event_sequence')->default(0);
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auctions');
    }
};
