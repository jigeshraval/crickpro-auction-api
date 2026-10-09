<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A paid (razorpay) or proof-reviewed (manual) self-registration. mode=none
 * never creates a row — today's direct-add path is unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_auction')->constrained('auctions')->restrictOnDelete(); // financial history — never cascade
            $table->foreignId('id_invitation')->nullable()->constrained('auction_invitations')->nullOnDelete();
            $table->json('payload'); // {name, role, battingStyle, bowlingStyle, city, phone}
            $table->string('mode', 16);   // razorpay|manual
            $table->string('status', 24); // awaiting_payment|awaiting_review|completed|expired|rejected|refunded|needs_review
            $table->foreignId('id_auction_player')->nullable()->constrained('auction_players')->nullOnDelete();
            $table->string('public_token', 48)->unique();
            $table->string('reject_reason')->nullable();
            $table->string('flag_reason')->nullable();
            $table->timestamps();

            $table->index(['id_auction', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_registrations');
    }
};
