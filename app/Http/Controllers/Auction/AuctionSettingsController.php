<?php

namespace App\Http\Controllers\Auction;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auction\ShowAuctionRequest;
use App\Http\Requests\Auction\UpdateAuctionSettingsRequest;
use App\Http\Resources\AuctionSettingResource;
use App\Models\Auction;
use App\Repositories\AuctionSettingRepository;
use Illuminate\Http\JsonResponse;

class AuctionSettingsController extends Controller
{
    public function __construct(
        private readonly AuctionSettingRepository $settings,
    ) {}

    public function show(ShowAuctionRequest $request, Auction $auction): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'settings' => new AuctionSettingResource($auction->settings),
        ]);
    }

    public function update(UpdateAuctionSettingsRequest $request, Auction $auction): JsonResponse
    {
        // The old dashboard only disabled this form client-side while
        // live/paused — rejecting it here too closes that gap rather than
        // trusting every client to behave.
        if (in_array($auction->status, [Auction::STATUS_LIVE, Auction::STATUS_PAUSED], true)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Settings are locked while the auction is live or paused.',
            ], 422);
        }

        $fieldsToColumns = [
            'initialPurse' => 'initial_purse',
            'squadMin' => 'squad_min',
            'squadMax' => 'squad_max',
            'minBasePrice' => 'min_base_price',
            'bidIncrementMode' => 'bid_increment_mode',
            'bidIncrementFlat' => 'bid_increment_flat',
            'bidIncrementSlabs' => 'bid_increment_slabs',
            'maxOverseasPerTeam' => 'max_overseas_per_team',
            'enforceSquadMax' => 'enforce_squad_max',
            'enforcePurse' => 'enforce_purse',
            'enforceOverseas' => 'enforce_overseas',
            'enforceSquadMin' => 'enforce_squad_min',
            'allowReentry' => 'allow_reentry',
            'allowCustomBid' => 'allow_custom_bid',
            'playerFields' => 'player_fields',
        ];

        $attributes = [];
        foreach ($fieldsToColumns as $field => $column) {
            if ($request->has($field)) {
                $attributes[$column] = $request->input($field);
            }
        }

        $settings = $this->settings->update($auction, $attributes);

        return response()->json([
            'status' => 'success',
            'settings' => new AuctionSettingResource($settings),
        ]);
    }
}
