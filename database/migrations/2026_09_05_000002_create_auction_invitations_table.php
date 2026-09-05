<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shareable "Invite Player" links. Each invitation carries a unique token (the
 * credential in the URL) and an optional category — a category-pinned invite
 * grades everyone who joins through it (base price from the category default),
 * a general invite leaves them uncategorised for the organiser to assign.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auction_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_auction')->constrained('auctions')->cascadeOnDelete();
            $table->string('token', 48)->unique();
            $table->string('category_code', 24)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('joined_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auction_invitations');
    }
};
