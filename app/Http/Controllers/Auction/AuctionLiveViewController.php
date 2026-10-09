<?php

namespace App\Http\Controllers\Auction;

use App\Http\Controllers\Controller;
use App\Models\Auction;
use App\Services\AuctionLiveView;
use Illuminate\Http\JsonResponse;

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
}
