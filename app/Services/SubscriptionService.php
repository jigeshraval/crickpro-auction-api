<?php

namespace App\Services;

use App\Models\Auction;
use App\Models\SubscriptionHistory;
use App\Models\User;
use App\Services\Payments\RazorpayClient;
use App\Services\Payments\SubscriptionPaymentsResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
    public function teamPacks(?string $currency = null): array
    {
        $currency = strtoupper($currency ?: (string) config('subscription.currency', 'INR'));
        $symbol = SubscriptionPaymentsResolver::symbol($currency);

        $packs = [];

        foreach ((array) config('subscription.team_packs', []) as $productId => $pack) {
            $teams = (int) (is_array($pack) ? ($pack['teams'] ?? 0) : $pack);
            // A price set directly on the pack (legacy/override config) wins;
            // otherwise take it from the per-currency price matrix.
            $legacy = is_array($pack) ? ($pack['price'] ?? null) : null;
            $raw = ($legacy !== null && $legacy !== '')
                ? (float) $legacy
                : SubscriptionPaymentsResolver::price($currency, (string) $productId);
            $price = $raw === null ? null : (float) $raw;

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
     * Start a web (Razorpay Payment Link) purchase of a team pack for an auction.
     * Creates a PENDING subscription_history row and a hosted payment link; the
     * `payment_link.paid` webhook activates it. Returns the link's short_url +
     * our reference id. $teams is the pack TIER (4/6/8/12/16/20/30).
     *
     * @return array{shortUrl: string, referenceId: string}
     */
    public function createRazorpayCheckout(User $user, Auction $auction, int $teams, string $currency, ?string $email = null): array
    {
        $planCode = $teams.'_teams';
        if (! array_key_exists($planCode, (array) config('subscription.team_packs', []))) {
            throw new \InvalidArgumentException("Unknown team pack: {$planCode}.");
        }

        $currency = strtoupper($currency);
        $price = SubscriptionPaymentsResolver::price($currency, $planCode);
        if ($price === null) {
            throw new \RuntimeException("No {$currency} price configured for {$planCode}.");
        }

        $amountMinor = $price * 100; // Razorpay charges the smallest unit.
        $referenceId = 'sub_'.$auction->id.'_'.Str::lower(Str::random(18));

        $sub = SubscriptionHistory::create([
            'id_user' => $user->id,
            'id_auction' => $auction->id,
            'plan_id' => $planCode,
            'max_teams' => $this->resolveMaxTeams($planCode),
            'amount' => $price,
            'currency' => $currency,
            'transaction_id' => $referenceId,
            'source' => 'razorpay',
            'store' => 'web',
            'is_active' => false,
            'status' => SubscriptionHistory::STATUS_PENDING,
            'metadata' => ['referenceId' => $referenceId, 'amountMinor' => $amountMinor],
        ]);

        $base = rtrim((string) config('services.auction_web.url', ''), '/');
        $callback = $base !== '' ? $base.'/subscription?status=processing' : null;

        try {
            $resp = app(RazorpayClient::class)->createPaymentLink(
                $amountMinor,
                $currency,
                "CrickPro Auction — up to {$teams} teams",
                $referenceId,
                [
                    'userId' => (string) $user->id,
                    'auctionId' => (string) $auction->id,
                    'teams' => (string) $teams,
                    'planCode' => $planCode,
                ],
                ['name' => (string) $user->name, 'email' => $email ?: $user->email, 'contact' => $user->mobile],
                $callback,
            );
        } catch (\Throwable $e) {
            // Never leave a dangling pending row if the link never got created.
            $sub->update(['status' => SubscriptionHistory::STATUS_CANCELLED, 'is_active' => false]);
            throw $e;
        }

        $sub->update(['metadata' => array_merge($sub->metadata ?? [], [
            'paymentLinkId' => $resp['id'] ?? null,
            'shortUrl' => $resp['short_url'] ?? null,
        ])]);

        return ['shortUrl' => (string) ($resp['short_url'] ?? ''), 'referenceId' => $referenceId];
    }

    /**
     * Activate a pending razorpay subscription from a `payment_link.paid` webhook.
     * Idempotent: unknown ref / already-active / amount-or-currency mismatch are
     * all no-ops. Returns the outcome string stored on the webhook event.
     */
    public function activateFromPaymentLink(array $link, array $payment): string
    {
        $ref = (string) ($link['reference_id'] ?? '');
        if ($ref === '') {
            return 'ignored: no reference_id';
        }

        $sub = SubscriptionHistory::where('transaction_id', $ref)->where('source', 'razorpay')->first();
        if (! $sub) {
            return 'ignored: unknown reference';
        }
        if ($sub->is_active && $sub->status === SubscriptionHistory::STATUS_ACTIVE) {
            return 'ignored: already active';
        }

        $currency = strtoupper((string) ($link['currency'] ?? ''));
        $paidMinor = (int) ($link['amount_paid'] ?? $link['amount'] ?? 0);
        $expectedMinor = (int) round(((float) $sub->amount) * 100);
        if ($currency !== strtoupper((string) $sub->currency) || $paidMinor < $expectedMinor) {
            return 'ignored: amount/currency mismatch';
        }

        $sub->update([
            'is_active' => true,
            'status' => SubscriptionHistory::STATUS_ACTIVE,
            'starts_at' => now(),
            'metadata' => array_merge($sub->metadata ?? [], [
                'paymentId' => $payment['id'] ?? null,
                'paymentLinkId' => $link['id'] ?? ($sub->metadata['paymentLinkId'] ?? null),
            ]),
        ]);

        $this->syncAuction($sub->id_auction);

        return 'completed';
    }

    /**
     * Backstop for a missed `payment_link.paid` webhook — the subscription
     * analogue of registration's QR poll (QrPollingService::pollOpen). Finds
     * pending razorpay rows old enough to have been paid, asks Razorpay for the
     * link status, and activates any that are `paid`. Idempotent (activation
     * no-ops an already-active row). Returns the number activated.
     */
    public function reconcilePending(): int
    {
        $minAge = (int) config('payments.poll_min_age_seconds', 20);
        $windowH = (int) config('subscription.reconcile_window_hours', 48);

        $rows = SubscriptionHistory::query()
            ->where('source', 'razorpay')
            ->where('status', SubscriptionHistory::STATUS_PENDING)
            ->where('is_active', false)
            ->where('created_at', '<=', now()->subSeconds($minAge))
            ->where('created_at', '>=', now()->subHours($windowH))
            ->orderBy('id')
            ->limit(200)
            ->get();

        $activated = 0;
        foreach ($rows as $row) {
            try {
                $links = app(RazorpayClient::class)->paymentLinksByReference((string) $row->transaction_id);
            } catch (\Throwable $e) {
                Log::warning('Subscription reconcile: link lookup failed', ['ref' => $row->transaction_id, 'error' => $e->getMessage()]);

                continue;
            }

            foreach ($links as $link) {
                if (($link['status'] ?? null) === 'paid') {
                    $result = $this->activateFromPaymentLink($link, []);
                    if ($result === 'completed') {
                        $activated++;
                    }
                    break;
                }
            }
        }

        return $activated;
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
