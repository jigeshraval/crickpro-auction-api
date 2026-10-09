<?php

namespace App\Services;

use App\Models\Auction;
use App\Repositories\AuctionFollowRepository;
use Illuminate\Support\Facades\Cache;

/**
 * Spectator payload for GET /auctions/{id}/view/live — the exact overlay state
 * (same shape the control device publishes RETAINED to auction/{id}/state, so
 * the app merges REST + MQTT with one code path) plus follow fields.
 *
 * The shared overlay body is cached 2s: a venue full of viewers behind one NAT IP
 * collapses to one build per 2s. follow fields are merged after, uncached.
 */
class AuctionLiveView
{
    private const CACHE_SECONDS = 2;

    public function __construct(
        private readonly AuctionOverlayState $overlay,
        private readonly AuctionFollowRepository $follows,
    ) {}

    /** @return array<string,mixed> */
    public function build(Auction $auction, ?int $viewerId): array
    {
        $body = Cache::remember(
            "auction-live-view:{$auction->id}",
            self::CACHE_SECONDS,
            fn () => $this->overlay->build($auction),
        );

        // Follow fields stay uncached (two PK lookups) so count + isFollowing never disagree.
        return $body + [
            'followersCount' => $this->follows->followersCount($auction->id),
            'isFollowing' => $this->follows->isFollowing($auction->id, $viewerId),
        ];
    }
}
