<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The "CrickPro Connect" login reads the CrickPro DB directly and has no handoff
 * token to store — so the link is created without one. `token` is only set by
 * the older HTTP relay flow. Make it nullable so a tokenless link can be created
 * (was TEXT NOT NULL with no default → 1364 on insert).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crickpro_links', function (Blueprint $table) {
            $table->text('token')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('crickpro_links', function (Blueprint $table) {
            $table->text('token')->nullable(false)->change();
        });
    }
};
