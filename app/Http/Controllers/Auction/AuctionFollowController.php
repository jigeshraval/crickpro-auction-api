<?php

namespace App\Http\Controllers\Auction;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicAuctionResource;
use App\Models\Auction;
use App\Repositories\AuctionFollowRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Follow / unfollow any auction by id (no access code), and the caller's followed list. */
class AuctionFollowController extends Controller
{
    public function __construct(private readonly AuctionFollowRepository $follows) {}

    public function store(Request $request, Auction $auction): JsonResponse
    {
        abort_if($auction->status === Auction::STATUS_ABANDONED, 404);
        $this->follows->follow($auction, $request->user()->id);

        return $this->respond($auction, true);
    }

    public function destroy(Request $request, Auction $auction): JsonResponse
    {
        $this->follows->unfollow($auction, $request->user()->id);

        return $this->respond($auction, false);
    }

    public function index(Request $request): JsonResponse
    {
        $auctions = $this->follows->paginateFollowed($request->user()->id, (int) $request->integer('perPage', 20));

        return response()->json([
            'status' => 'success',
            ...paginated(PublicAuctionResource::collection($auctions), 'auctions', true),
        ]);
    }

    private function respond(Auction $auction, bool $isFollowing): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'isFollowing' => $isFollowing,
            'followersCount' => $this->follows->followersCount($auction->id),
        ]);
    }
}
