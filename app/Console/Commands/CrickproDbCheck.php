<?php

namespace App\Console\Commands;

use App\Models\Auction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Diagnose the `crickpro` DB connection used by "Push to CrickPro" — prints the
 * resolved host/database and, for an auction, the linked tournament + the live
 * squad counts the exporter writes to. Run on each environment to confirm the
 * push targets the SAME CrickPro DB the app/api reads.
 *
 *   php artisan crickpro:db-check --auction=2
 *   php artisan crickpro:db-check --series=24713
 */
class CrickproDbCheck extends Command
{
    protected $signature = 'crickpro:db-check {--auction= : auction id} {--series= : crickpro series id}';

    protected $description = 'Show which CrickPro DB the push writes to + squad counts';

    public function handle(): int
    {
        $cfg = config('database.connections.crickpro');
        $this->info('crickpro connection → '.($cfg['host'] ?? '?').':'.($cfg['port'] ?? '?').'/'.($cfg['database'] ?? '?').' as '.($cfg['username'] ?? '?'));

        $cp = DB::connection('crickpro');

        try {
            $cp->getPdo();
            $this->info('connection OK');
        } catch (\Throwable $e) {
            $this->error('connection FAILED: '.$e->getMessage());

            return self::FAILURE;
        }

        $seriesId = $this->option('series') ? (int) $this->option('series') : null;

        if ($this->option('auction')) {
            $auction = Auction::find((int) $this->option('auction'));
            if (! $auction) {
                $this->error('auction not found');

                return self::FAILURE;
            }
            $this->line("auction {$auction->id}: {$auction->name}  →  id_crickpro_series=".($auction->id_crickpro_series ?? 'NULL'));
            $seriesId = $seriesId ?: (int) $auction->id_crickpro_series;

            $teams = $auction->teams()->get(['id', 'name', 'id_crickpro_team']);
            foreach ($teams as $t) {
                $this->line("  team {$t->id} \"{$t->name}\"  id_crickpro_team=".($t->id_crickpro_team ?? 'NULL'));
            }
        }

        if ($seriesId) {
            $s = $cp->table('series')->where('id', $seriesId)->first(['id', 'name']);
            $this->line('series '.$seriesId.': '.($s ? $s->name : 'NOT FOUND in this DB'));
            $rows = $cp->table('series_team_squads')
                ->where('id_series', $seriesId)
                ->whereNull('deleted_at')
                ->selectRaw('id_team, count(*) as n')
                ->groupBy('id_team')
                ->get();
            $total = 0;
            foreach ($rows as $r) {
                $name = $cp->table('teams')->where('id', $r->id_team)->value('name');
                $this->line("  team {$r->id_team} \"{$name}\": {$r->n} squad players");
                $total += $r->n;
            }
            $this->info("total active squad rows for series {$seriesId}: {$total}");
        }

        return self::SUCCESS;
    }
}
