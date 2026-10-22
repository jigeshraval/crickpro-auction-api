<?php

namespace App\Services;

use App\Models\Auction;
use App\Models\SubscriptionHistory;
use Illuminate\Support\Carbon;

/**
 * Auction subscription business logic. Team packs are per-auction: buying a pack
 * grants that auction a `max_teams` allowance + overlay access. The active record's
 * entitlement is denormalised onto the auction for cheap gate checks.
 */
class SubscriptionService
{
    /** Store product id (`30_teams`) → max teams. Falls back to the leading number. */
    public function resolveMaxTeams(string $planId): int
    {
        $map = config('subscription.team_packs', []);
        if (isset($map[$planId])) {
            // A pack is `['teams' => N, 'price' => ...]`; a bare int is still
            // accepted so an older/overridden config keeps working.
            return (int) (is_array($map[$planId]) ? ($map[$planId]['teams'] ?? 0) : $map[$planId]);
        }
        if (preg_match('/(\d+)/', $planId, $m)) {
            return (int) $m[1];
        }
        return 0;
    }

    /**
     * The team-pack catalogue, ascending by team count — what a client renders on
     * its subscription screen.
     *
     * The native apps get their packages (and the real, localised price) from the
     * store via RevenueCat and don't need this. The web build has no billing SDK
     * to ask, so it reads the tiers from here instead of hardcoding a list that
     * would drift from the one this service actually enforces. `priceString` is
     * null for any pack with no configured price.
     */
    public function teamPacks(): array
    {
        $symbol = (string) config('subscription.currency_symbol', '');
        $currency = (string) config('subscription.currency', '');

        $packs = [];

        foreach ((array) config('subscription.team_packs', []) as $productId => $pack) {
            $teams = (int) (is_array($pack) ? ($pack['teams'] ?? 0) : $pack);
            $raw = is_array($pack) ? ($pack['price'] ?? null) : null;
            $price = ($raw === null || $raw === '') ? null : (float) $raw;

            $packs[] = [
                'productId' => (string) $productId,
                'teams' => $teams,
                'price' => $price,
                'currency' => $currency,
                // Whole amounts read better without the trailing ".00".
                'priceString' => $price === null
                    ? null
                    : $symbol.number_format($price, fmod($price, 1.0) === 0.0 ? 0 : 2),
            ];
        }

        usort($packs, fn (array $a, array $b) => $a['teams'] <=> $b['teams']);

        return $packs;
    }

    /**
     * Record a store purchase for an auction (client confirm). Idempotent on
     * transaction_id. Activates immediately; the RevenueCat webhook reconciles
     * refunds/expiry later.
     */
    public function confirm(int $userId, ?int $auctionId, string $planId, ?string $transactionId, ?string $store, array $meta = []): SubscriptionHistory
    {
        if ($transactionId) {
            $existing = SubscriptionHistory::where('transaction_id', $transactionId)->first();
            if ($existing) {
                return $existing;
            }
        }

        $sub = SubscriptionHistory::create([
            'id_user' => $userId,
            'id_auction' => $auctionId,
            'plan_id' => $planId,
            'max_teams' => $this->resolveMaxTeams($planId),
            'transaction_id' => $transactionId,
            'source' => 'revenuecat',
            'store' => $store,
            'starts_at' => now(),
            'is_active' => true,
            'status' => SubscriptionHistory::STATUS_ACTIVE,
            'metadata' => $meta ?: null,
        ]);

        $this->syncAuction($auctionId);

        return $sub;
    }

    /** Admin/ops manual grant. */
    public function grantManual(int $adminId, int $userId, ?int $auctionId, int $maxTeams, ?string $planId, ?Carbon $expiresAt, ?float $amount, ?string $currency, ?string $notes): SubscriptionHistory
    {
        $sub = SubscriptionHistory::create([
            'id_user' => $userId,
            'id_auction' => $auctionId,
            'plan_id' => $planId ?? ($maxTeams.'_teams'),
            'max_teams' => $maxTeams,
            'amount' => $amount,
            'currency' => $currency,
            'source' => 'manual',
            'store' => 'admin',
            'starts_at' => now(),
            'expires_at' => $expiresAt,
            'is_active' => true,
            'status' => SubscriptionHistory::STATUS_ACTIVE,
            'created_by' => $adminId,
            'notes' => $notes,
        ]);

        $this->syncAuction($auctionId);

        return $sub;
    }

    /** Soft-delete (admin revoke) and resync the affected auction. */
    public function revoke(SubscriptionHistory $sub): void
    {
        $sub->update(['status' => SubscriptionHistory::STATUS_DELETED, 'is_active' => false]);
        $this->syncAuction($sub->id_auction);
    }

    /** The live subscription granting the most teams for an auction, if any. */
    public function activeForAuction(int $auctionId): ?SubscriptionHistory
    {
        return SubscriptionHistory::query()->live()
            ->where('id_auction', $auctionId)
            ->orderByDesc('max_teams')
            ->first();
    }

    /**
     * Team allowance for an auction: free baseline, raised by an active pack.
     *
     * Reads the live subscription directly, NOT the denormalised `auctions.max_teams`
     * column. The denorm column can lag the ledger (a purchase whose syncAuction ran
     * before the row went live, a webhook-activated row, a direct grant) — gating off
     * it paywalls a user who genuinely owns a bigger pack. The ledger is the only
     * source of truth a gate may trust; the column stays for cheap display/overlay reads.
     */
    public function allowanceFor(Auction $auction): int
    {
        $active = $this->activeForAuction($auction->id);

        return max((int) config('subscription.free_teams', 2), (int) ($active?->max_teams ?? 0));
    }

    /** Does the auction's plan actually cover its current team count? */
    public function coversTeams(Auction $auction): bool
    {
        return $auction->teams()->count() <= $this->allowanceFor($auction);
    }

    /** Denormalise the active entitlement onto the auction row. */
    public function syncAuction(?int $auctionId): void
    {
        if (! $auctionId) {
            return;
        }
        $auction = Auction::find($auctionId);
        if (! $auction) {
            return;
        }
        $active = $this->activeForAuction($auctionId);
        $auction->update([
            'max_teams' => $active?->max_teams,
            'overlay_access' => (bool) $active,
        ]);
    }
}
