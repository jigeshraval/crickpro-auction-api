<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auction_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_auction')->constrained('auctions')->cascadeOnDelete();
            $table->string('code', 24);
            $table->string('name', 60);
            $table->char('color', 7)->default('#8B5CF6');
            $table->bigInteger('default_base_price')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['id_auction', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auction_categories');
    }
};
