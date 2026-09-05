<?php

namespace App\Http\Controllers\Auction;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auction\ShowAuctionRequest;
use App\Http\Requests\Auction\SyncAuctionCategoriesRequest;
use App\Http\Resources\AuctionCategoryResource;
use App\Models\Auction;
use App\Models\AuctionCategory;
use App\Models\AuctionPlayer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AuctionCategoryController extends Controller
{
    public function index(ShowAuctionRequest $request, Auction $auction): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'categories' => AuctionCategoryResource::collection($auction->categories()->orderBy('sort_order')->get()),
        ]);
    }

    /**
     * Replace the auction's category set (Edit Auction screen). Upsert by code,
     * delete anything the organiser removed — but refuse to drop a category that
     * still has players assigned, so no player is orphaned onto a dead code.
     */
    public function sync(SyncAuctionCategoriesRequest $request, Auction $auction): JsonResponse
    {
        $incoming = collect($request->input('categories', []))
            ->map(fn ($c) => [
                'code' => strtoupper(trim($c['code'])),
                'name' => trim($c['name']),
                'color' => $c['color'] ?? null,
                'default_base_price' => $c['defaultBasePrice'] ?? 0,
            ])
            ->values();

        $incomingCodes = $incoming->pluck('code')->all();
        $existingCodes = $auction->categories()->pluck('code')->all();
        $removedCodes = array_values(array_diff($existingCodes, $incomingCodes));

        if ($removedCodes) {
            $blocked = AuctionPlayer::where('id_auction', $auction->id)
                ->whereIn('category_code', $removedCodes)
                ->pluck('category_code')
                ->unique()
                ->values()
                ->all();
            if ($blocked) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Reassign players before removing category '.implode(', ', $blocked).'.',
                ], 422);
            }
        }

        DB::transaction(function () use ($auction, $incoming, $removedCodes) {
            if ($removedCodes) {
                $auction->categories()->whereIn('code', $removedCodes)->delete();
            }
            foreach ($incoming as $i => $c) {
                AuctionCategory::updateOrCreate(
                    ['id_auction' => $auction->id, 'code' => $c['code']],
                    ['name' => $c['name'], 'color' => $c['color'], 'default_base_price' => $c['default_base_price'], 'sort_order' => $i],
                );
            }
        });

        return response()->json([
            'status' => 'success',
            'categories' => AuctionCategoryResource::collection($auction->categories()->orderBy('sort_order')->get()),
        ]);
    }
}
