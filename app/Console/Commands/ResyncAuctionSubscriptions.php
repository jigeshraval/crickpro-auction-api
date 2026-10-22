<?php

namespace App\Console\Commands;

use App\Models\Auction;
use App\Services\SubscriptionService;
use Illuminate\Console\Command;

/**
 * Rebuild the denormalised `auctions.max_teams` / `auctions.overlay_access` columns
 * from the live subscription ledger.
 *
 * Gates no longer trust these columns (SubscriptionService::allowanceFor reads the
 * ledger directly), but they still feed display + the app's cached team-add button,
 * and they can drift — a webhook-activated row, a purchase whose sync ran before the
 * row went live, or an auction update that clobbered the column. Run this to repair
 * drift across the fleet, or target one auction.
 *
 *   php artisan auctions:resync-subscriptions
 *   php artisan auctions:resync-subscriptions --auction=12
 *   php artisan auctions:resync-subscriptions --dry-run
 */
class ResyncAuctionSubscriptions extends Command
{
    protected $signature = 'auctions:resync-subscriptions {--auction= : limit to one auction id} {--dry-run : report drift without writing}';

    protected $description = 'Rebuild auctions.max_teams / overlay_access from the live subscription ledger';

    public function handle(SubscriptionService $subs): int
    {
        $dry = (bool) $this->option('dry-run');

        $query = Auction::query();
        if ($id = $this->option('auction')) {
            $query->whereKey((int) $id);
        }

        $fixed = 0;
        $checked = 0;

        $query->orderBy('id')->chunkById(200, function ($auctions) use ($subs, $dry, &$fixed, &$checked) {
            foreach ($auctions as $auction) {
                $checked++;
                $active = $subs->activeForAuction($auction->id);
                $wantMax = $active?->max_teams;
                $wantOverlay = (bool) $active;

                $curMax = $auction->max_teams;
                $curOverlay = (bool) $auction->overlay_access;

                if ((int) $curMax === (int) $wantMax && $curOverlay === $wantOverlay) {
                    continue;
                }

                $this->warn(sprintf(
                    'auction %d: max_teams %s → %s, overlay_access %s → %s',
                    $auction->id,
                    var_export($curMax, true),
                    var_export($wantMax, true),
                    var_export($curOverlay, true),
                    var_export($wantOverlay, true),
                ));

                if (! $dry) {
                    $auction->update(['max_teams' => $wantMax, 'overlay_access' => $wantOverlay]);
                }
                $fixed++;
            }
        });

        $this->info(sprintf('%s %d of %d auction(s).', $dry ? 'Would fix' : 'Resynced', $fixed, $checked));

        return self::SUCCESS;
    }
}
