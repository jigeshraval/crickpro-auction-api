<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_auction')->constrained('auctions')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('short_name', 12);
            $table->string('logo_url', 500)->nullable();
            $table->char('primary_color', 7)->default('#6D28D9');
            $table->char('secondary_color', 7)->default('#DB2777');
            $table->string('owner_name', 120)->nullable();
            $table->bigInteger('initial_purse');
            $table->bigInteger('remaining_purse');
            $table->char('shortcut_key', 1)->nullable();
            $table->unsignedInteger('display_order')->default(0);
            $table->string('access_code', 12)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teams');
    }
};
