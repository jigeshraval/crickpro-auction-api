<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links an auction back to the CrickPro tournament (series) it was started from,
 * so "Start Auctioning" is idempotent: a repeat provision reuses the same auction
 * (updating its name/logo + recreating teams) instead of making a duplicate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auctions', function (Blueprint $table) {
            $table->unsignedBigInteger('id_crickpro_series')->nullable()->after('id_owner')->index();
        });
    }

    public function down(): void
    {
        Schema::table('auctions', function (Blueprint $table) {
            $table->dropColumn('id_crickpro_series');
        });
    }
};
