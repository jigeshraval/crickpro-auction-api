<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional expiry for invite links. Stored in UTC; the admin picks a local
 * date/time which the client converts to UTC (toISOString) before sending.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auction_invitations', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('category_code');
        });
    }

    public function down(): void
    {
        Schema::table('auction_invitations', function (Blueprint $table) {
            $table->dropColumn('expires_at');
        });
    }
};
