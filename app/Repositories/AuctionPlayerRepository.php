<?php

namespace App\Repositories;

use App\Models\Auction;
use App\Models\AuctionCategory;
use App\Models\AuctionPlayer;
use App\Models\Player;
use App\Models\RoleType;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class AuctionPlayerRepository
{
    /**
     * Build sets (categories) from player roles: one category per role type that
     * appears among the auction's players, then assign each player to their role's
     * set. Existing role categories keep their colour/base price.
     */
    public function generateSetsFromRoles(Auction $auction): int
    {
        return DB::transaction(function () use ($auction) {
            $palette = [1 => '#e6c66a', 2 => '#5b8def', 3 => '#3ddc84', 4 => '#b06ae6'];
            $players = AuctionPlayer::where('id_auction', $auction->id)->with('player.roleType')->get();

            $usedIds = $players->pluck('player.id_role_type')->filter()->unique();
            $roleTypes = RoleType::whereIn('id', $usedIds)->orderBy('sort_order')->get();

            foreach ($roleTypes as $rt) {
                $cat = AuctionCategory::firstOrNew(['id_auction' => $auction->id, 'code' => $rt->short]);
                $cat->name = $rt->name;
                $cat->sort_order = $rt->sort_order;
                if (! $cat->exists) {
                    $cat->color = $palette[$rt->id] ?? '#8B5CF6';
                    $cat->default_base_price = 0;
                }
                $cat->save();
            }

            $assigned = 0;
            foreach ($players as $ap) {
                $rt = $ap->player?->roleType;
                if ($rt) {
                    $ap->update(['category_code' => $rt->short]);
                    $assigned++;
                }
            }

            return $assigned;
        });
    }

    public function paginateForAuction(Auction $auction, ?string $status, ?string $search, int $perPage): LengthAwarePaginator
    {
        return AuctionPlayer::with('player')
            ->where('id_auction', $auction->id)
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($search, fn ($q) => $q->whereHas('player', fn ($p) => $p->where('name', 'like', '%'.$search.'%')))
            ->orderBy('auction_order')
            ->paginate($perPage);
    }

    /**
     * Every auction-player id in auction order, unfiltered and unpaginated.
     *
     * Rides along on the list response because the clients need it for two
     * things a page of rows cannot answer: the position badge on a row of page
     * 2, and reorder, which replaces the WHOLE order array. Without it the
     * client has to fetch the list a second time at perPage=9999 on every
     * render — which is exactly what crickpro-auction was doing.
     */
    public function orderedIdsForAuction(Auction $auction): array
    {
        return AuctionPlayer::where('id_auction', $auction->id)
            ->orderBy('auction_order')
            ->pluck('id')
            ->all();
    }

    /** Row counts per status across the WHOLE auction (not just the current page/filter), plus `all` — what the status-filter chips show. */
    public function countsForAuction(Auction $auction): array
    {
        $counts = AuctionPlayer::where('id_auction', $auction->id)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->all();

        $counts['all'] = array_sum($counts);

        return $counts;
    }

    /**
     * Creates the library `Player` row and the auction-scoped `AuctionPlayer`
     * row together — mirrors the Node app's single "Add Player" form doing
     * both at once (its Edit form is what splits identity vs auction-scoped
     * fields into two calls; update() below accepts both in one PATCH instead,
     * simpler for a single mobile form to submit).
     */
    public function add(Auction $auction, array $playerAttributes, array $auctionPlayerAttributes): AuctionPlayer
    {
        return DB::transaction(function () use ($auction, $playerAttributes, $auctionPlayerAttributes) {
            $player = Player::create(['id_owner' => $auction->id_owner, ...$playerAttributes]);

            return $this->attach($auction, $player, $auctionPlayerAttributes);
        });
    }

    public function attach(Auction $auction, Player $player, array $auctionPlayerAttributes): AuctionPlayer
    {
        $order = $auctionPlayerAttributes['auction_order'] ?? $auction->auctionPlayers()->count();

        if (empty($auctionPlayerAttributes['base_price']) && ! empty($auctionPlayerAttributes['category_code'])) {
            $category = AuctionCategory::where('id_auction', $auction->id)
                ->where('code', $auctionPlayerAttributes['category_code'])
                ->first();
            $auctionPlayerAttributes['base_price'] = $category->default_base_price ?? 0;
        }

        $auctionPlayer = AuctionPlayer::create([
            'id_auction' => $auction->id,
            'id_player' => $player->id,
            'auction_order' => $order,
            ...$auctionPlayerAttributes,
        ]);

        // Columns left to their migration default (status, round_number, ...)
        // were never set on this in-memory instance — only what create() was
        // actually given. Reload so the caller sees what's really stored.
        return $auctionPlayer->refresh();
    }

    public function update(AuctionPlayer $auctionPlayer, array $playerAttributes, array $auctionPlayerAttributes): AuctionPlayer
    {
        DB::transaction(function () use ($auctionPlayer, $playerAttributes, $auctionPlayerAttributes) {
            if ($playerAttributes) {
                $auctionPlayer->player->update($playerAttributes);
            }
            if ($auctionPlayerAttributes) {
                $auctionPlayer->update($auctionPlayerAttributes);
            }
        });

        return $auctionPlayer->refresh();
    }

    /** 422s in the controller if already SOLD — this only ever deletes the roster row, never the library player. */
    public function remove(AuctionPlayer $auctionPlayer): void
    {
        $auctionPlayer->delete();
    }

    public function reorder(Auction $auction, array $orderedAuctionPlayerIds): void
    {
        DB::transaction(function () use ($auction, $orderedAuctionPlayerIds) {
            foreach ($orderedAuctionPlayerIds as $index => $id) {
                AuctionPlayer::where('id_auction', $auction->id)->where('id', $id)->update(['auction_order' => $index]);
            }
        });
    }

    public function shuffle(Auction $auction): void
    {
        $ids = AuctionPlayer::where('id_auction', $auction->id)->pluck('id')->shuffle()->values();
        $this->reorder($auction, $ids->all());
    }

    /**
     * Pasted-text bulk add, one player per line: `name, role, category, price, phone`.
     * Trailing fields are optional — ports the Node app's parsePlayerText.
     */
    public function bulkAdd(Auction $auction, string $text): int
    {
        $lines = array_filter(array_map('trim', explode("\n", $text)));
        $created = 0;

        DB::transaction(function () use ($auction, $lines, &$created) {
            foreach ($lines as $line) {
                $parts = array_map('trim', explode(',', $line));
                $name = $parts[0] ?? null;
                if (! $name) {
                    continue;
                }

                $role = $this->normalizeRole($parts[1] ?? null);
                $categoryCode = ! empty($parts[2]) ? mb_strtoupper($parts[2]) : null;
                $price = isset($parts[3]) && is_numeric($parts[3]) ? (int) $parts[3] : null;
                $phone = $parts[4] ?? null;

                $player = Player::create([
                    'id_owner' => $auction->id_owner,
                    'name' => $name,
                    'role' => $role,
                    'phone' => $phone,
                ]);

                $this->attach($auction, $player, array_filter([
                    'category_code' => $categoryCode,
                    'base_price' => $price,
                ], fn ($v) => $v !== null));

                $created++;
            }
        });

        return $created;
    }

    /** Attaches existing library players into this auction's pool, skipping ones already in it. */
    public function addFromLibrary(Auction $auction, array $playerIds, ?string $categoryCode = null, ?int $basePrice = null): int
    {
        $alreadyAttached = AuctionPlayer::where('id_auction', $auction->id)->whereIn('id_player', $playerIds)->pluck('id_player');
        $toAttach = array_diff($playerIds, $alreadyAttached->all());
        $attached = 0;

        // Only the set keys — attach() fills base_price from the category's
        // default when a code is given and no explicit price is passed.
        $attrs = array_filter([
            'category_code' => $categoryCode ? mb_strtoupper($categoryCode) : null,
            'base_price' => $basePrice,
        ], fn ($v) => $v !== null);

        DB::transaction(function () use ($auction, $toAttach, $attrs, &$attached) {
            foreach (Player::whereIn('id', $toAttach)->where('id_owner', $auction->id_owner)->get() as $player) {
                $this->attach($auction, $player, $attrs);
                $attached++;
            }
        });

        return $attached;
    }

    private function normalizeRole(?string $raw): string
    {
        $role = mb_strtolower(trim((string) $raw));
        $map = [
            'batter' => Player::ROLE_BATTER, 'bat' => Player::ROLE_BATTER, 'batsman' => Player::ROLE_BATTER,
            'bowler' => Player::ROLE_BOWLER, 'bowl' => Player::ROLE_BOWLER,
            'all_rounder' => Player::ROLE_ALL_ROUNDER, 'all-rounder' => Player::ROLE_ALL_ROUNDER, 'allrounder' => Player::ROLE_ALL_ROUNDER,
            'wicket_keeper' => Player::ROLE_WICKET_KEEPER, 'keeper' => Player::ROLE_WICKET_KEEPER, 'wk' => Player::ROLE_WICKET_KEEPER,
            'wk_batter' => Player::ROLE_WK_BATTER,
        ];

        return $map[$role] ?? Player::ROLE_BATTER;
    }
}
