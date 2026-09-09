<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rounded logos/images are the intended default look — flip the column default
 * to ON and turn it on for every existing auction (the flag was new, so no one
 * had deliberately chosen OFF yet).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auctions', function (Blueprint $table) {
            $table->boolean('image_rounded')->default(true)->change();
        });

        DB::table('auctions')->update(['image_rounded' => true]);
    }

    public function down(): void
    {
        Schema::table('auctions', function (Blueprint $table) {
            $table->boolean('image_rounded')->default(false)->change();
        });
    }
};
