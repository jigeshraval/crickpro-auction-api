<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auction_overlays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_auction')->constrained('auctions')->cascadeOnDelete();
            $table->foreignId('id_overlay_template')->constrained('overlay_templates')->restrictOnDelete();
            $table->boolean('is_active')->default(false);
            $table->enum('display_mode', ['full_frame', 'lower_third'])->default('full_frame');
            $table->json('config')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auction_overlays');
    }
};
