<?php

namespace App\Http\Controllers;

use App\Http\Resources\SubscriptionHistoryResource;
use App\Models\Auction;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function __construct(private readonly SubscriptionService $subs) {}

    /**
     * Per-auction entitlement for the signed-in user. `auctionId` optional — when
     * given, returns that auction's team allowance + overlay access.
     */
    public function status(Request $request): JsonResponse
    {
        $auctionId = $request->integer('auctionId') ?: null;
        $maxTeams = 0;
        $isSubscribed = false;

        if ($auctionId) {
            $active = $this->subs->activeForAuction($auctionId);
            $maxTeams = $active?->max_teams ?? 0;
            $isSubscribed = (bool) $active;
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'isSubscribed' => $isSubscribed,
                'maxTeams' => (int) $maxTeams,
                'freeTeams' => (int) config('subscription.free_teams', 3),
            ],
        ]);
    }

    /** Client-side purchase confirmation (RevenueCat). Idempotent on transactionId. */
    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate([
            'productId' => 'required|string|max:100',
            'transactionId' => 'nullable|string|max:255',
            'auctionId' => 'nullable|integer|exists:auctions,id',
            'teams' => 'nullable|integer',
            'store' => 'nullable|string|max:50',
        ]);

        // Only the owner can attach a purchase to their auction.
        if (! empty($data['auctionId'])) {
            $auction = Auction::find($data['auctionId']);
            if ($auction && $auction->id_owner !== $request->user()->id) {
                return response()->json(['status' => 'error', 'message' => 'Not your auction.'], 403);
            }
        }

        $sub = $this->subs->confirm(
            $request->user()->id,
            $data['auctionId'] ?? null,
            $data['productId'],
            $data['transactionId'] ?? null,
            $data['store'] ?? 'play_store',
        );

        return response()->json([
            'status' => 'success',
            'subscription' => new SubscriptionHistoryResource($sub),
        ], 201);
    }
}
