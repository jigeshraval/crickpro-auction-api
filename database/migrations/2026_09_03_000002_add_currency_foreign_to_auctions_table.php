<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the free-text currency_code/currency_symbol columns with a proper
 * id_currency FK — one source of truth (the currencies table) instead of
 * two guessed-at strings duplicated onto every auction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auctions', function (Blueprint $table) {
            $table->dropColumn(['currency_code', 'currency_symbol']);
            $table->foreignId('id_currency')->after('id_owner')->constrained('currencies')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('auctions', function (Blueprint $table) {
            $table->dropForeign(['id_currency']);
            $table->dropColumn('id_currency');
            $table->char('currency_code', 3)->default('INR');
            $table->string('currency_symbol', 8)->default('₹');
        });
    }
};
