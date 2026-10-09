<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The user's country: `country` (ISO2, what feature gating reads — e.g.
 * registration payments are India-only) and `id_country` (the local
 * `countries` row, resolved by name from the app's country picker).
 * Backfill: every existing +91 mobile is India.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->char('country', 2)->nullable()->index();
            $table->foreignId('id_country')->nullable()->constrained('countries')->nullOnDelete();
        });

        $this->backfill();
    }

    /** Idempotent; public so tests can re-run it on seeded rows. */
    public function backfill(): void
    {
        DB::table('users')
            ->whereNull('country')
            ->where('mobile', 'like', '+91%')
            ->update(['country' => 'IN']);

        $indiaId = DB::table('countries')->whereRaw('LOWER(name) = ?', ['india'])->value('id');
        if ($indiaId) {
            DB::table('users')
                ->where('country', 'IN')
                ->whereNull('id_country')
                ->update(['id_country' => $indiaId]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('id_country');
            $table->dropIndex(['country']);
            $table->dropColumn('country');
        });
    }
};
