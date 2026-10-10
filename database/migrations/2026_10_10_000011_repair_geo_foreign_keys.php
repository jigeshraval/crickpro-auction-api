<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * After the singular rename, some databases kept foreign keys that still point
 * at the old plural tables (e.g. state.id_country → `countries`, which no longer
 * exists), so inserts fail with 1452. Re-point every geo FK at `country` / `state`.
 *
 * MySQL only — sqlite (tests) rewrites FK references on RENAME already.
 * Idempotent: drops whatever FK currently sits on each column, then re-adds it.
 */
return new class extends Migration
{
    /** [table, column, referenced table] */
    private const FKS = [
        ['state', 'id_country', 'country'],
        ['city', 'id_state', 'state'],
        ['city', 'id_country', 'country'],
        ['users', 'id_country', 'country'],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach (self::FKS as [$table, $column, $references]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            // Values that point at a missing parent would block the new FK.
            DB::table($table)->whereNotNull($column)
                ->whereNotIn($column, DB::table($references)->select('id'))
                ->update([$column => null]);

            foreach ($this->foreignKeys($table, $column) as $name) {
                Schema::table($table, fn (Blueprint $t) => $t->dropForeign($name));
            }

            Schema::table($table, fn (Blueprint $t) => $t->foreign($column)->references('id')->on($references)->nullOnDelete());
        }
    }

    public function down(): void
    {
        // Nothing to undo — the repaired FKs are the correct ones.
    }

    /** @return list<string> */
    private function foreignKeys(string $table, string $column): array
    {
        return DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $column)
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->pluck('CONSTRAINT_NAME')
            ->all();
    }
};
