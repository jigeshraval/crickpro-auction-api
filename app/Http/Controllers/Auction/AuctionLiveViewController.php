<?php

namespace App\Http\Controllers\Auction;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicAuctionPlayerResource;
use App\Models\Auction;
use App\Repositories\AuctionPlayerRepository;
use App\Services\AuctionLiveView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Spectator live view — any auction by id (no access code), login optional.
 * Route sits outside auth:sanctum; the sanctum guard still resolves a bearer
 * token when one is sent so isFollowing is accurate for signed-in viewers.
 * Soft-deleted auctions 404 via route-model binding; abandoned ones 404 here.
 */
class AuctionLiveViewController extends Controller
{
    public function show(Auction $auction, AuctionLiveView $view): JsonResponse
    {
        abort_if($auction->status === Auction::STATUS_ABANDONED, 404);

        return response()->json([
            'status' => 'success',
            'data' => $view->build($auction, auth('sanctum')->id()),
        ]);
    }

    /**
     * PUBLIC, read-only player pool for the browse view (`/view-auction/:id`,
     * Players tab) — the companion to show() above. Unlike the live snapshot,
     * which a security audit flagged for leaking any auction by id whether or
     * not its owner ever listed it, this endpoint is deliberately gated: it
     * 404s unless the auction is LISTED (the owner opted into public discovery)
     * and not abandoned. Soft-deleted auctions already 404 via route binding.
     *
     * Rows use PublicAuctionPlayerResource (phone/PII stripped). `teams` maps
     * every team id → name so the client can label a SOLD row without a second
     * request. Shape mirrors AuctionPlayerController@index so the web reuses
     * its player/meta types.
     */
    public function players(Request $request, Auction $auction, AuctionPlayerRepository $auctionPlayers): JsonResponse
    {
        abort_unless($auction->is_listed, 404);
        abort_if($auction->status === Auction::STATUS_ABANDONED, 404);

        $players = $auctionPlayers->paginateForAuction(
            $auction,
            $request->string('status')->value() ?: null,
            $request->string('search')->value() ?: null,
            (int) $request->integer('perPage', 25),
        );

        return response()->json([
            'status' => 'success',
            'players' => PublicAuctionPlayerResource::collection($players),
            'teams' => $auction->teams()->orderBy('display_order')->pluck('name', 'id'),
            'meta' => [
                'page' => $players->currentPage(),
                'perPage' => $players->perPage(),
                'total' => $players->total(),
                'totalPages' => $players->lastPage(),
            ],
        ]);
    }
}
