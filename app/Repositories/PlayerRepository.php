<?php

namespace App\Repositories;

use App\Models\AuctionPlayer;
use App\Models\Player;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class PlayerRepository
{
    /** The owner's whole reusable library — paged, filterable by name/role. */
    public function paginateForOwner(int $ownerId, ?string $search, ?string $role, int $perPage): LengthAwarePaginator
    {
        return Player::query()
            ->where('id_owner', $ownerId)
            ->when($search, fn ($q) => $q->where('name', 'like', '%'.$search.'%'))
            ->when($role, fn ($q) => $q->where('role', $role))
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function create(int $ownerId, array $attributes): Player
    {
        return Player::create(['id_owner' => $ownerId, ...$attributes]);
    }

    public function update(Player $player, array $attributes): Player
    {
        $player->update($attributes);

        return $player;
    }

    /** Soft delete — past auction results still reference this player by id. */
    public function delete(Player $player): void
    {
        $player->delete();
    }

    /** This player's row in every auction they've ever been part of, newest first. */
    public function history(Player $player): Collection
    {
        return AuctionPlayer::with(['auction', 'soldToTeam'])
            ->where('id_player', $player->id)
            ->latest()
            ->get();
    }

    /**
     * historyCount/bestSoldPrice per player — the library list's compact
     * "N auctions · best ₹X" line, computed for a whole page at once.
     */
    public function withHistoryStats(LengthAwarePaginator $players): LengthAwarePaginator
    {
        $ids = $players->pluck('id');

        $stats = AuctionPlayer::whereIn('id_player', $ids)
            ->selectRaw('id_player, count(*) as history_count, max(case when status = ? then sold_price end) as best_sold_price', [AuctionPlayer::STATUS_SOLD])
            ->groupBy('id_player')
            ->get()
            ->keyBy('id_player');

        foreach ($players as $player) {
            $row = $stats->get($player->id);
            $player->setAttribute('history_count', (int) ($row->history_count ?? 0));
            $player->setAttribute('best_sold_price', $row->best_sold_price ?? null);
        }

        return $players;
    }
}
