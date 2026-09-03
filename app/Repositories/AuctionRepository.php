<?php

namespace App\Repositories;

use App\Models\Auction;
use App\Models\AuctionCategory;
use App\Models\AuctionOverlay;
use App\Models\AuctionSetting;
use App\Models\BroadcastSession;
use App\Models\Currency;
use App\Models\OverlayTemplate;
use App\Models\Team;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AuctionRepository
{
    /** Ported from crickpro-auction-api (Node)'s auctionService.quickStart(). */
    private const DEFAULT_CATEGORIES = [
        ['code' => 'A', 'name' => 'Category A', 'color' => '#F59E0B', 'defaultBasePrice' => 50_000],
        ['code' => 'B', 'name' => 'Category B', 'color' => '#8B5CF6', 'defaultBasePrice' => 20_000],
        ['code' => 'C', 'name' => 'Category C', 'color' => '#3B82F6', 'defaultBasePrice' => 10_000],
    ];

    private const DEFAULT_OVERLAY_CODE = 'nakshatra';

    private const DEFAULT_CURRENCY_CODE = 'INR';

    /**
     * One screen from nothing to an auction ready for teams and players —
     * same shape as the source's quick-start. Teams/players/overlay theme
     * are optional; everything the caller skips gets a sane server default.
     *
     * @return array{auction: Auction, broadcastToken: string, slug: string}
     */
    public function quickStart(int $ownerId, array $input): array
    {
        $auction = DB::transaction(function () use ($ownerId, $input) {
            $slug = $this->generateSlug($input['name']);
            $currency = $this->resolveCurrency($input['currencyId'] ?? null);

            $auction = Auction::create([
                'id_owner' => $ownerId,
                'id_currency' => $currency->id,
                'name' => $input['name'],
                'slug' => $slug,
                'season_label' => $input['seasonLabel'] ?? null,
                'venue' => $input['venue'] ?? null,
                'access_code' => $this->generateAccessCode(),
                'scheduled_at' => $input['scheduledAt'] ?? null,
                // READY, not DRAFT — if the pieces are here, it's ready. Making
                // the organiser click "mark ready" adds a step and prevents
                // nothing (source auctionService.ts's own reasoning).
                'status' => Auction::STATUS_READY,
            ]);

            AuctionSetting::create(array_filter([
                'id_auction' => $auction->id,
                'initial_purse' => $input['purse'] ?? null,
                'squad_max' => $input['squadMax'] ?? null,
                'squad_min' => isset($input['squadMax']) ? min(11, $input['squadMax']) : null,
                'min_base_price' => $input['minBasePrice'] ?? null,
                'bid_increment_mode' => $input['bidIncrementMode'] ?? null,
                'bid_increment_flat' => $input['bidIncrementFlat'] ?? null,
                'bid_increment_slabs' => $input['bidIncrementSlabs'] ?? null,
            ], fn ($v) => $v !== null));

            $categories = $input['categories'] ?? self::DEFAULT_CATEGORIES;
            foreach ($categories as $i => $category) {
                AuctionCategory::create([
                    'id_auction' => $auction->id,
                    'code' => strtoupper($category['code']),
                    'name' => $category['name'],
                    'color' => $category['color'] ?? self::DEFAULT_CATEGORIES[$i % count(self::DEFAULT_CATEGORIES)]['color'],
                    'default_base_price' => $category['defaultBasePrice'] ?? 0,
                    'sort_order' => $i,
                ]);
            }

            // No fallback team list — a quick-started auction starts with an
            // empty roster; the organiser adds real teams from the Teams tab.
            foreach ($input['teams'] ?? [] as $i => $team) {
                $purse = $input['purse'] ?? 100_000_000;
                Team::create(array_filter([
                    'id_auction' => $auction->id,
                    'name' => $team['name'],
                    'short_name' => mb_strtoupper(mb_substr($team['shortName'], 0, 12)),
                    'primary_color' => $team['primaryColor'] ?? null,
                    'secondary_color' => $team['secondaryColor'] ?? '#FFFFFF',
                    'initial_purse' => $purse,
                    'remaining_purse' => $purse,
                    'shortcut_key' => $i < 9 ? (string) ($i + 1) : null,
                    'display_order' => $i,
                ], fn ($v) => $v !== null));
            }

            // The organiser's chosen theme, or the default. An unknown code
            // falls back rather than failing, and if even the default row
            // isn't seeded, the auction just has no overlay yet.
            $template = (isset($input['overlayCode'])
                ? OverlayTemplate::where('code', $input['overlayCode'])->first()
                : null) ?? OverlayTemplate::where('code', self::DEFAULT_OVERLAY_CODE)->first();

            if ($template) {
                AuctionOverlay::create([
                    'id_auction' => $auction->id,
                    'id_overlay_template' => $template->id,
                    'is_active' => true,
                ]);
            }

            // Columns left to their migration default (is_listed,
            // current_round, ...) were never set on this in-memory instance —
            // only what create() was actually given. Reload so the response
            // reflects what's really stored.
            return $auction->refresh();
        });

        // Issued outside the transaction, same as the source — the raw token
        // is shown once and can never be recovered, so it must never be part
        // of anything that could roll back.
        $issued = BroadcastSession::issue($auction->id);

        return ['auction' => $auction, 'broadcastToken' => $issued['plainToken'], 'slug' => $auction->slug];
    }

    public function update(Auction $auction, array $attributes): Auction
    {
        $auction->update($attributes);

        return $auction;
    }

    public function start(Auction $auction): Auction
    {
        if ($auction->status !== Auction::STATUS_READY) {
            throw new \RuntimeException('Only a ready auction can be started.');
        }
        $auction->update(['status' => Auction::STATUS_LIVE, 'started_at' => now()]);

        return $auction;
    }

    public function pause(Auction $auction): Auction
    {
        if ($auction->status !== Auction::STATUS_LIVE) {
            throw new \RuntimeException('Only a live auction can be paused.');
        }
        $auction->update(['status' => Auction::STATUS_PAUSED, 'paused_at' => now()]);

        return $auction;
    }

    public function resume(Auction $auction): Auction
    {
        if ($auction->status !== Auction::STATUS_PAUSED) {
            throw new \RuntimeException('Only a paused auction can be resumed.');
        }
        $auction->update(['status' => Auction::STATUS_LIVE, 'paused_at' => null]);

        return $auction;
    }

    public function complete(Auction $auction): Auction
    {
        if (! in_array($auction->status, [Auction::STATUS_LIVE, Auction::STATUS_PAUSED], true)) {
            throw new \RuntimeException('Only a live or paused auction can be completed.');
        }
        $auction->update(['status' => Auction::STATUS_COMPLETED, 'completed_at' => now()]);

        return $auction;
    }

    /** Blocked while live/paused — a room full of people bidding and no undo. */
    public function delete(Auction $auction): void
    {
        if (in_array($auction->status, [Auction::STATUS_LIVE, Auction::STATUS_PAUSED], true)) {
            throw new \RuntimeException('Complete or abandon the auction before deleting it.');
        }
        $auction->delete();
    }

    /** The owner's own auctions — "My Auctions", newest first. */
    public function paginateForOwner(int $ownerId, ?string $search, int $perPage): LengthAwarePaginator
    {
        return Auction::query()
            ->withCount(['teams', 'auctionPlayers'])
            ->with('settings')
            ->where('id_owner', $ownerId)
            ->when($search, fn ($q) => $q->where('name', 'like', '%'.$search.'%'))
            ->latest()
            ->paginate($perPage);
    }

    /**
     * Every publicly listed auction from every OTHER organiser — the
     * Dashboard's "All Auctions" feed. Excludes the caller's own: this
     * answers "what's on out there", not "what's mine" — that's My
     * Auction's job.
     */
    public function paginatePublic(int $excludeOwnerId, ?string $search, int $perPage): LengthAwarePaginator
    {
        return Auction::query()
            ->with(['settings', 'currency'])
            ->where('is_listed', true)
            ->where('id_owner', '!=', $excludeOwnerId)
            ->when($search, fn ($q) => $q->where('name', 'like', '%'.$search.'%'))
            ->latest()
            ->paginate($perPage);
    }

    /**
     * Unlike the overlay template, a currency is required — id_currency has
     * no NULL fallback, so an unresolvable id (or a missing default row)
     * fails loudly here instead of leaving the auction half-created.
     */
    private function resolveCurrency(?int $currencyId): Currency
    {
        $currency = $currencyId
            ? Currency::find($currencyId)
            : Currency::where('code', self::DEFAULT_CURRENCY_CODE)->first();

        if (! $currency) {
            throw new \RuntimeException('No currency available — has CurrencySeeder been run?');
        }

        return $currency;
    }

    private function generateSlug(string $name): string
    {
        $slug = Str::slug($name);
        if ($slug === '') {
            $slug = 'auction';
        }

        if (Auction::withTrashed()->where('slug', $slug)->exists()) {
            $slug .= '-'.Str::random(6);
        }

        return mb_strtolower($slug);
    }

    /**
     * 8 random digits formatted NNNNN-NNN — digits only, deliberately no
     * letters, since this gets read aloud in a noisy auction hall where
     * B/P/D and M/N are audibly ambiguous.
     */
    private function generateAccessCode(): string
    {
        for ($i = 0; $i < 8; $i++) {
            $digits = str_pad((string) random_int(0, 99_999_999), 8, '0', STR_PAD_LEFT);
            $code = substr($digits, 0, 5).'-'.substr($digits, 5, 3);

            if (! Auction::withTrashed()->where('access_code', $code)->exists()) {
                return $code;
            }
        }

        throw new \RuntimeException('Could not generate a unique access code');
    }
}
