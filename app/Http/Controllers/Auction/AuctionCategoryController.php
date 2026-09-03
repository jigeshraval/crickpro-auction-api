<?php

namespace App\Http\Controllers\Auction;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auction\ShowAuctionRequest;
use App\Http\Resources\AuctionCategoryResource;
use App\Models\Auction;
use Illuminate\Http\JsonResponse;

class AuctionCategoryController extends Controller
{
    public function index(ShowAuctionRequest $request, Auction $auction): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'categories' => AuctionCategoryResource::collection($auction->categories()->orderBy('sort_order')->get()),
        ]);
    }
}
