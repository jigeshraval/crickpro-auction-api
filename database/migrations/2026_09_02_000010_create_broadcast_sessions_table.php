<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The OBS/stream-viewer credential for one auction's overlay. Only the hash
 * is stored — the plaintext token is returned once, at issue time, the same
 * way Sanctum personal access tokens already work in this app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('broadcast_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_auction')->constrained('auctions')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('label', 60)->nullable();
            $table->boolean('is_revoked')->default(false);
            $table->timestamp('last_seen_at')->nullable();
            $table->unsignedInteger('view_count')->default(0);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broadcast_sessions');
    }
};
