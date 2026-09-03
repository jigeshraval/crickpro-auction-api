<?php

namespace App\Http\Controllers\Auction;

use App\Exceptions\AuctionControlException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auction\MarkSoldRequest;
use App\Http\Requests\Auction\PlaceBidRequest;
use App\Http\Requests\Auction\ReturnToPoolRequest;
use App\Http\Requests\Auction\SelectPlayerRequest;
use App\Http\Requests\Auction\ShowAuctionRequest;
use App\Models\Auction;
use App\Repositories\AuctionControlRepository;
use Illuminate\Http\JsonResponse;

class AuctionControlController extends Controller
{
    public function __construct(
        private readonly AuctionControlRepository $control,
    ) {}

    public function state(ShowAuctionRequest $request, Auction $auction): JsonResponse
    {
        return $this->respond($auction, fn () => $this->control->buildState($auction));
    }

    public function selectPlayer(SelectPlayerRequest $request, Auction $auction): JsonResponse
    {
        return $this->respond($auction, fn () => $this->control->selectPlayer($auction, $request->integer('auctionPlayerId') ?: null));
    }

    public function nextPlayer(ShowAuctionRequest $request, Auction $auction): JsonResponse
    {
        return $this->respond($auction, fn () => $this->control->nextPlayer($auction));
    }

    public function openBidding(ShowAuctionRequest $request, Auction $auction): JsonResponse
    {
        return $this->respond($auction, fn () => $this->control->openBidding($auction));
    }

    public function bid(PlaceBidRequest $request, Auction $auction): JsonResponse
    {
        return $this->respond($auction, fn () => $this->control->placeBid($auction, $request->integer('teamId'), $request->has('amount') ? $request->integer('amount') : null));
    }

    public function sold(MarkSoldRequest $request, Auction $auction): JsonResponse
    {
        return $this->respond($auction, fn () => $this->control->markSold(
            $auction,
            $request->has('teamId') ? $request->integer('teamId') : null,
            $request->has('amount') ? $request->integer('amount') : null,
            $request->boolean('overrideRules'),
        ));
    }

    public function unsold(ShowAuctionRequest $request, Auction $auction): JsonResponse
    {
        return $this->respond($auction, fn () => $this->control->markUnsold($auction));
    }

    public function returnToPool(ReturnToPoolRequest $request, Auction $auction): JsonResponse
    {
        return $this->respond($auction, fn () => $this->control->returnToPool(
            $auction,
            $request->integer('auctionPlayerId'),
            $request->has('newBasePrice') ? $request->integer('newBasePrice') : null,
        ));
    }

    public function undo(ShowAuctionRequest $request, Auction $auction): JsonResponse
    {
        return $this->respond($auction, fn () => $this->control->undo($auction));
    }

    private function respond(Auction $auction, \Closure $action): JsonResponse
    {
        try {
            $state = $action();
        } catch (AuctionControlException $e) {
            return response()->json(['status' => 'error', 'code' => $e->errorCode, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['status' => 'success', 'state' => $state]);
    }
}
