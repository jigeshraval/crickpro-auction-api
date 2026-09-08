<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auction-level "round images" toggle. When on, the overlay's TRANSPARENT mode
 * renders framed images (logos) as circles; television keeps them as-designed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auctions', function (Blueprint $table) {
            $table->boolean('image_rounded')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('auctions', function (Blueprint $table) {
            $table->dropColumn('image_rounded');
        });
    }
};
