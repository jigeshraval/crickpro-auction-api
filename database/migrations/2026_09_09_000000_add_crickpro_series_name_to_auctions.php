<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record the linked CrickPro tournament's NAME alongside its id, so the auction
 * can show "Connected to <tournament>" without a cross-DB lookup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auctions', function (Blueprint $table) {
            $table->string('crickpro_series_name')->nullable()->after('id_crickpro_series');
        });
    }

    public function down(): void
    {
        Schema::table('auctions', function (Blueprint $table) {
            $table->dropColumn('crickpro_series_name');
        });
    }
};
