<?php

namespace App\Repositories;

use App\Models\Auction;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Support\Facades\DB;

/**
 * Server-side auction follows. insertOrIgnore on the (auction, user) unique key
 * makes follow idempotent; the denormalized auctions.followers_count only moves
 * when a row was actually inserted/deleted. Counter writes go through the query
 * builder (not Eloquent) so they never touch auctions.updated_at — the Live/Past
 * lists order by it.
 */
class AuctionFollowRepository
{
    public function follow(Auction $auction, int $userId): void
    {
        DB::transaction(function () use ($auction, $userId) {
            $inserted = DB::table('auction_followers')->insertOrIgnore([
                'id_auction' => $auction->id,
                'id_user' => $userId,
                'created_at' => now(),
            ]);

            if ($inserted > 0) {
                DB::table('auctions')->where('id', $auction->id)->increment('followers_count');
            }
        });
    }

    public function unfollow(Auction $auction, int $userId): void
    {
        DB::transaction(function () use ($auction, $userId) {
            $deleted = DB::table('auction_followers')
                ->where('id_auction', $auction->id)
                ->where('id_user', $userId)
                ->delete();

            if ($deleted > 0) {
                DB::table('auctions')->where('id', $auction->id)->where('followers_count', '>', 0)->decrement('followers_count');
            }
        });
    }

    public function isFollowing(int $auctionId, ?int $userId): bool
    {
        if ($userId === null) {
            return false;
        }

        return DB::table('auction_followers')->where('id_auction', $auctionId)->where('id_user', $userId)->exists();
    }

    public function followersCount(int $auctionId): int
    {
        return (int) DB::table('auctions')->where('id', $auctionId)->value('followers_count');
    }

    /** Followed auctions, newest follow first. Abandoned + soft-deleted auctions drop out. */
    public function paginateFollowed(int $userId, int $perPage): Paginator
    {
        // select() BEFORE withCount — a later select() would replace the list and drop teams_count.
        return Auction::query()
            ->select('auctions.*')
            ->with(['settings', 'currency'])
            ->withCount('teams')
            ->join('auction_followers as af', 'af.id_auction', '=', 'auctions.id')
            ->where('af.id_user', $userId)
            ->where('auctions.status', '!=', Auction::STATUS_ABANDONED)
            ->orderByDesc('af.created_at')
            ->orderByDesc('af.id')
            ->simplePaginate($perPage);
    }
}
