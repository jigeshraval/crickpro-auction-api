<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Web subscription purchases pay via a Razorpay Payment Link, so the
 * subscription_history.source enum gains a 'razorpay' value (was
 * revenuecat/manual/promo). ->change() is cross-driver (MySQL MODIFY + the
 * SQLite table-rebuild used in tests), unlike a raw ALTER … MODIFY ENUM.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_history', function (Blueprint $table) {
            $table->enum('source', ['revenuecat', 'manual', 'promo', 'razorpay'])->default('revenuecat')->change();
        });
    }

    public function down(): void
    {
        Schema::table('subscription_history', function (Blueprint $table) {
            $table->enum('source', ['revenuecat', 'manual', 'promo'])->default('revenuecat')->change();
        });
    }
};
