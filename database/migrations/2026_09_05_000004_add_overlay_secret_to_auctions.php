<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A stable, owner-retrievable secret that gates the auction overlay link
 * (`/overlay/{code}/{mode}?key={secret}`) — same idea as a match's secret_key in
 * crickpro-app. Kept plaintext (not a hash) precisely because the owner must be
 * able to re-copy the same link any time; BroadcastSession stays for view-count.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auctions', function (Blueprint $table) {
            $table->string('overlay_secret', 64)->nullable()->after('access_code');
        });
    }

    public function down(): void
    {
        Schema::table('auctions', function (Blueprint $table) {
            $table->dropColumn('overlay_secret');
        });
    }
};
