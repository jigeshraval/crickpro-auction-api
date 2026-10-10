<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The auction's city / state / country (same ids as crickpro-api-v2) plus a
 * denormalised `location` label ("City, State, Country"). `venue` stays the
 * free-text ground name. `location` can't be a generated column (it reads
 * other tables) — App\Services\AuctionLocation keeps it in step, applied from
 * the Auction model's `saving` hook.
 *
 * Idempotent (hasColumn guards), like the other 2026_10_10 geo migrations.
 */
return new class extends Migration
{
    /** [column, referenced table] */
    private const FKS = [
        ['id_city', 'city'],
        ['id_state', 'state'],
        ['id_country', 'country'],
    ];

    public function up(): void
    {
        foreach (self::FKS as [$column, $references]) {
            if (! Schema::hasColumn('auctions', $column)) {
                Schema::table('auctions', fn (Blueprint $t) => $t->foreignId($column)->nullable()->constrained($references)->nullOnDelete());
            }
        }

        if (! Schema::hasColumn('auctions', 'location')) {
            Schema::table('auctions', fn (Blueprint $t) => $t->string('location', 255)->nullable());
        }
    }

    public function down(): void
    {
        foreach (self::FKS as [$column]) {
            if (Schema::hasColumn('auctions', $column)) {
                Schema::table('auctions', fn (Blueprint $t) => $t->dropConstrainedForeignId($column));
            }
        }

        if (Schema::hasColumn('auctions', 'location')) {
            Schema::table('auctions', fn (Blueprint $t) => $t->dropColumn('location'));
        }
    }
};
