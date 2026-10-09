<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A second payment for a registration that was already completed (late
 * payment on an older QR) is credited to the organiser and flagged so they
 * can find and refund it. Status `dispute_lost` (string column) needs no DDL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registration_payments', function (Blueprint $table) {
            $table->boolean('is_duplicate')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('registration_payments', function (Blueprint $table) {
            $table->dropColumn('is_duplicate');
        });
    }
};
