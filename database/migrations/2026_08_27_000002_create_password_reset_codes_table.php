<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Final clean shape directly (crickpro-api's equivalent table went through
     * a rename/nullable-column migration dance we don't need to repeat here).
     * `identifier` holds an email OR a formatted `+<phonecode><number>` string;
     * `channel` disambiguates which. `id_user` is nullable because a WhatsApp
     * OTP can be sent for phone verification before we know which user it is.
     */
    public function up(): void
    {
        Schema::create('password_reset_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_user')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('identifier', 255);
            $table->string('code', 6);
            $table->enum('channel', ['email', 'whatsapp'])->default('email');
            $table->boolean('used')->default(false);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index(['identifier', 'code', 'used', 'expires_at'], 'prc_lookup_idx');
            $table->index(['id_user', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_codes');
    }
};
