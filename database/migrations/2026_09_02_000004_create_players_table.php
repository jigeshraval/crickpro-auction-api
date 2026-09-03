<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The player master library — not auction-scoped. An organiser's players live
 * here once and get attached to individual auctions via auction_players.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_owner')->constrained('users')->restrictOnDelete();
            // Set only when the player self-registered via an access code
            // (see the real crickpro-app's join flow) rather than being
            // added by the organiser.
            $table->foreignId('id_user')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 120);
            $table->string('display_name', 60)->nullable();
            $table->string('photo_url', 500)->nullable();
            $table->enum('role', ['batter', 'bowler', 'all_rounder', 'wicket_keeper', 'wk_batter'])->default('batter');
            $table->enum('batting_style', ['right_hand', 'left_hand'])->nullable();
            $table->string('bowling_style', 40)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('nationality', 60)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('phone', 20)->nullable();
            $table->unsignedInteger('jersey_number')->nullable();
            $table->boolean('is_capped')->default(false);
            $table->json('stats')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('players');
    }
};
