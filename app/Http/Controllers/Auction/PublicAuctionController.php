<?php

namespace App\Http\Controllers\Auction;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicAuctionPlayerResource;
use App\Http\Resources\PublicAuctionResource;
use App\Http\Resources\TeamResource;
use App\Models\Auction;
use App\Repositories\AuctionControlRepository;
use App\Repositories\AuctionPlayerRepository;
use App\Repositories\TeamRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The unauthenticated "I have an access code" surface — no auth:sanctum,
 * reached by anyone the code was shared with, not just the auction's owner.
 * Deliberately narrower than the owner-scoped Auction/Team/AuctionPlayer
 * controllers: read-only (no create/update/delete route exists here at all),
 * and PublicAuctionPlayerResource strips the player's phone number, the one
 * PII field the owner-facing AuctionPlayerResource carries.
 *
 * `is_listed` is not checked anywhere here — that flag only controls whether
 * an auction is discoverable in the public browse feed (AuctionController::
 * publicIndex), not whether a known code can open it. A code, listed or not,
 * is itself the credential.
 */
class PublicAuctionController extends Controller
{
    public function __construct(
        private readonly TeamRepository $teams,
        private readonly AuctionPlayerRepository $auctionPlayers,
        private readonly AuctionControlRepository $control,
    ) {}

    public function lookup(Request $request): JsonResponse
    {
        $request->validate(['code' => 'required|string']);

        $auction = $this->findByCode($request->string('code')->value());

        return response()->json([
            'status' => 'success',
            'auction' => new PublicAuctionResource($auction),
        ]);
    }

    public function teams(string $code): JsonResponse
    {
        $auction = $this->findByCode($code);

        return response()->json([
            'status' => 'success',
            'teams' => TeamResource::collection($this->teams->listForAuction($auction)),
        ]);
    }

    public function players(Request $request, string $code): JsonResponse
    {
        $auction = $this->findByCode($code);

        $players = $this->auctionPlayers->paginateForAuction(
            $auction,
            $request->string('status')->value() ?: null,
            $request->string('search')->value() ?: null,
            (int) $request->integer('perPage', 25),
        );

        return response()->json([
            'status' => 'success',
            'players' => PublicAuctionPlayerResource::collection($players),
            'meta' => [
                'page' => $players->currentPage(),
                'perPage' => $players->perPage(),
                'total' => $players->total(),
                'totalPages' => $players->lastPage(),
            ],
        ]);
    }

    /**
     * The same snapshot the owner's Control Room polls, for anyone holding
     * the code — read-only, no bid/sold/undo routes exist on this
     * controller at all. Reuses AuctionControlRepository::buildState()
     * directly since it's already owner-agnostic (just reads state).
     */
    public function state(string $code): JsonResponse
    {
        $auction = $this->findByCode($code);

        return response()->json([
            'status' => 'success',
            'state' => $this->control->buildState($auction),
        ]);
    }

    /** 422 rather than 404 — a wrong code is a form mistake the code-entry screen shows inline, not a missing-page navigation error. */
    private function findByCode(string $code): Auction
    {
        $auction = Auction::where('access_code', $code)->first();

        if (! $auction) {
            throw ValidationException::withMessages(['code' => 'No auction found for that code.']);
        }

        return $auction;
    }
}
