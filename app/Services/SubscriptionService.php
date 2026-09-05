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
            return (int) $map[$planId];
        }
        if (preg_match('/(\d+)/', $planId, $m)) {
            return (int) $m[1];
        }
        return 0;
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
