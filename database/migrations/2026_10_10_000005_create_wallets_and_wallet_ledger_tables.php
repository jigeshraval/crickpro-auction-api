<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `wallets` exists to be locked (SELECT … FOR UPDATE) — one row per owner.
 * `wallet_ledger` is append-only; balance = Σ amount (signed paise).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_owner')->unique()->constrained('users');
            $table->string('razorpayx_contact_id', 40)->nullable();
            $table->timestamp('negative_alerted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('wallet_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_owner')->constrained('users');
            $table->string('type', 24);
            $table->bigInteger('amount');
            $table->timestamp('available_at');
            $table->string('ref_type', 32)->nullable();
            $table->unsignedBigInteger('ref_id')->nullable();
            $table->string('idempotency_key', 100)->unique();
            $table->string('description')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['id_owner', 'available_at']);
            $table->index(['id_owner', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_ledger');
        Schema::dropIfExists('wallets');
    }
};
