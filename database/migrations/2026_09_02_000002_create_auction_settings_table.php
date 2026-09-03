<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auction_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_auction')->unique()->constrained('auctions')->cascadeOnDelete();
            $table->bigInteger('initial_purse')->default(100_000_000);
            $table->unsignedInteger('squad_min')->default(11);
            $table->unsignedInteger('squad_max')->default(15);
            $table->bigInteger('min_base_price')->default(0);
            $table->enum('bid_increment_mode', ['flat', 'slab'])->default('slab');
            $table->bigInteger('bid_increment_flat')->default(500_000);
            $table->json('bid_increment_slabs')->nullable();
            $table->unsignedInteger('max_overseas_per_team')->nullable();
            $table->boolean('enforce_squad_max')->default(true);
            $table->boolean('enforce_purse')->default(true);
            $table->boolean('enforce_overseas')->default(false);
            $table->boolean('enforce_squad_min')->default(false);
            $table->boolean('allow_reentry')->default(true);
            $table->boolean('allow_custom_bid')->default(true);
            $table->json('player_fields')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auction_settings');
    }
};
