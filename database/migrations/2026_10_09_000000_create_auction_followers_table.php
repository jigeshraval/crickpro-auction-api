<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auction_followers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_auction')->constrained('auctions')->cascadeOnDelete();
            $table->foreignId('id_user')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['id_auction', 'id_user']);
            $table->index(['id_user', 'created_at']);
        });

        Schema::table('auctions', function (Blueprint $table) {
            $table->unsignedInteger('followers_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('auctions', function (Blueprint $table) {
            $table->dropColumn('followers_count');
        });
        Schema::dropIfExists('auction_followers');
    }
};
