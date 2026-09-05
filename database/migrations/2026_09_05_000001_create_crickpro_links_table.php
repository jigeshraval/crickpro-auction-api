<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links an auction-app user to their main CrickPro account, for the "Import
 * from CrickPro App" flow. One link per auction user: the Sanctum token issued
 * by crickpro-api-v2's verify-access-code, plus the crickpro user id/name for
 * display. The token is what the relay endpoints use to fetch teams/players.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crickpro_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_owner')->unique()->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('crickpro_user_id');
            $table->text('token');
            $table->string('name')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crickpro_links');
    }
};
