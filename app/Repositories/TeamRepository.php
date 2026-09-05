<?php

namespace App\Repositories;

use App\Models\Auction;
use App\Models\AuctionPlayer;
use App\Models\Team;
use Illuminate\Database\Eloquent\Collection;

class TeamRepository
{
    /** Cycled by team count on create — ported from the Node app's team-creation logic. */
    private const COLOR_PALETTE = [
        '#6D28D9', '#DB2777', '#059669', '#D97706',
        '#2563EB', '#DC2626', '#0891B2', '#7C3AED',
    ];

    public function listForAuction(Auction $auction): Collection
    {
        return $this->withComputedFields($auction->teams()->orderBy('display_order')->get(), $auction);
    }

    public function create(Auction $auction, array $attributes): Team
    {
        $count = $auction->teams()->count();
        $purse = $auction->settings?->initial_purse ?? 100_000_000;

        return $auction->teams()->create([
            'name' => $attributes['name'],
            'short_name' => mb_strtoupper($attributes['shortName']),
            // Organiser's pick, else the next palette colour cycled by team count.
            'primary_color' => isset($attributes['primaryColor'])
                ? strtoupper($attributes['primaryColor'])
                : self::COLOR_PALETTE[$count % count(self::COLOR_PALETTE)],
            'secondary_color' => '#FFFFFF',
            'initial_purse' => $purse,
            'remaining_purse' => $purse,
            'shortcut_key' => $count < 9 ? (string) ($count + 1) : null,
            'display_order' => $count,
        ]);
    }

    public function update(Team $team, array $attributes): Team
    {
        $team->update($attributes);

        return $team;
    }

    /** 422s (via a thrown exception the controller catches) if the team has bought players. */
    public function delete(Team $team): void
    {
        $team->delete();
    }

    public function hasBoughtPlayers(Team $team): bool
    {
        return AuctionPlayer::where('id_sold_to_team', $team->id)->exists();
    }

    /**
     * Annotates each team with boughtCount/slotsLeft/maxBid — the same figures
     * the Team Insights cards show live during bidding (Phase 2's Control
     * Room reuses this exact helper, not a second copy of the math).
     */
    public function withComputedFields(Collection $teams, Auction $auction): Collection
    {
        $settings = $auction->settings;
        $squadMax = $settings?->squad_max ?? 15;
        $minBasePrice = $settings?->min_base_price ?? 0;
        $enforceSquadMax = (bool) ($settings?->enforce_squad_max ?? true);

        $boughtCounts = AuctionPlayer::where('id_auction', $auction->id)
            ->where('status', AuctionPlayer::STATUS_SOLD)
            ->whereNotNull('id_sold_to_team')
            ->selectRaw('id_sold_to_team, count(*) as total')
            ->groupBy('id_sold_to_team')
            ->pluck('total', 'id_sold_to_team');

        foreach ($teams as $team) {
            $boughtCount = (int) ($boughtCounts[$team->id] ?? 0);
            $slotsLeft = max(0, $squadMax - $boughtCount);

            $maxBid = $enforceSquadMax
                ? $team->remaining_purse - max(0, $slotsLeft - 1) * $minBasePrice
                : $team->remaining_purse;

            $team->setAttribute('bought_count', $boughtCount);
            $team->setAttribute('slots_left', $slotsLeft);
            $team->setAttribute('max_bid', max(0, $maxBid));
        }

        return $teams;
    }
}
