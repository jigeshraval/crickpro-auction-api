<?php

namespace App\Http\Controllers\Auction;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auction\AddPlayersFromLibraryRequest;
use App\Http\Requests\Auction\BulkAddPlayersRequest;
use App\Http\Requests\Auction\DeleteAuctionPlayerRequest;
use App\Http\Requests\Auction\ReorderAuctionPlayersRequest;
use App\Http\Requests\Auction\ShowAuctionPlayerRequest;
use App\Http\Requests\Auction\ShowAuctionRequest;
use App\Http\Requests\Auction\StoreAuctionPlayerRequest;
use App\Http\Requests\Auction\UpdateAuctionPlayerRequest;
use App\Http\Resources\AuctionPlayerResource;
use App\Models\Auction;
use App\Models\AuctionPlayer;
use App\Repositories\AuctionPlayerRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuctionPlayerController extends Controller
{
    public function __construct(
        private readonly AuctionPlayerRepository $auctionPlayers,
    ) {}

    public function index(ShowAuctionRequest $request, Auction $auction): JsonResponse
    {
        $players = $this->auctionPlayers->paginateForAuction(
            $auction,
            $request->string('status')->value() ?: null,
            $request->string('search')->value() ?: null,
            (int) $request->integer('perPage', 25),
        );

        return response()->json([
            'status' => 'success',
            'players' => AuctionPlayerResource::collection($players),
            'allIds' => $this->auctionPlayers->orderedIdsForAuction($auction),
            'counts' => $this->auctionPlayers->countsForAuction($auction),
            'meta' => [
                'page' => $players->currentPage(),
                'perPage' => $players->perPage(),
                'total' => $players->total(),
                'totalPages' => $players->lastPage(),
            ],
        ]);
    }

    /** One row, for the clients' player-detail screen — the list endpoint has no way to ask for a single id. */
    public function show(ShowAuctionPlayerRequest $request, Auction $auction, AuctionPlayer $auctionPlayer): JsonResponse
    {
        $auctionPlayer->load('player');

        return response()->json([
            'status' => 'success',
            'player' => new AuctionPlayerResource($auctionPlayer),
        ]);
    }

    public function store(StoreAuctionPlayerRequest $request, Auction $auction): JsonResponse
    {
        [$playerAttributes, $auctionPlayerAttributes] = $this->splitAttributes($request);

        $auctionPlayer = $this->auctionPlayers->add($auction, $playerAttributes, $auctionPlayerAttributes);
        $auctionPlayer->load('player');

        return response()->json([
            'status' => 'success',
            'player' => new AuctionPlayerResource($auctionPlayer),
        ], 201);
    }

    public function update(UpdateAuctionPlayerRequest $request, Auction $auction, AuctionPlayer $auctionPlayer): JsonResponse
    {
        [$playerAttributes, $auctionPlayerAttributes] = $this->splitAttributes($request);

        $auctionPlayer = $this->auctionPlayers->update($auctionPlayer, $playerAttributes, $auctionPlayerAttributes);
        $auctionPlayer->load('player');

        return response()->json([
            'status' => 'success',
            'player' => new AuctionPlayerResource($auctionPlayer),
        ]);
    }

    public function destroy(DeleteAuctionPlayerRequest $request, Auction $auction, AuctionPlayer $auctionPlayer): JsonResponse
    {
        if ($auctionPlayer->status === AuctionPlayer::STATUS_SOLD) {
            return response()->json([
                'status' => 'error',
                'message' => 'This player has already sold — return them to the pool first.',
            ], 422);
        }

        $this->auctionPlayers->remove($auctionPlayer);

        return response()->json(['status' => 'success']);
    }

    public function reorder(ReorderAuctionPlayersRequest $request, Auction $auction): JsonResponse
    {
        $this->auctionPlayers->reorder($auction, $request->input('orderedIds'));

        return response()->json(['status' => 'success']);
    }

    public function shuffle(ShowAuctionRequest $request, Auction $auction): JsonResponse
    {
        $this->auctionPlayers->shuffle($auction);

        return response()->json(['status' => 'success']);
    }

    public function bulkAdd(BulkAddPlayersRequest $request, Auction $auction): JsonResponse
    {
        $created = $this->auctionPlayers->bulkAdd($auction, $request->input('text'));

        return response()->json(['status' => 'success', 'created' => $created]);
    }

    public function addFromLibrary(AddPlayersFromLibraryRequest $request, Auction $auction): JsonResponse
    {
        $attached = $this->auctionPlayers->addFromLibrary($auction, $request->input('playerIds'));

        return response()->json(['status' => 'success', 'attached' => $attached]);
    }

    /** @return array{0: array, 1: array} [playerAttributes, auctionPlayerAttributes] */
    private function splitAttributes(Request $request): array
    {
        $playerFields = [
            'name' => 'name', 'phone' => 'phone', 'role' => 'role',
            'battingStyle' => 'batting_style', 'bowlingStyle' => 'bowling_style',
            'dateOfBirth' => 'date_of_birth', 'city' => 'city',
            'jerseyNumber' => 'jersey_number', 'isCapped' => 'is_capped',
        ];
        $auctionPlayerFields = [
            'categoryCode' => 'category_code', 'basePrice' => 'base_price',
            'isOverseas' => 'is_overseas', 'customFieldValues' => 'custom_field_values',
        ];

        $playerAttributes = [];
        foreach ($playerFields as $field => $column) {
            if ($request->has($field)) {
                $playerAttributes[$column] = $request->input($field);
            }
        }

        $auctionPlayerAttributes = [];
        foreach ($auctionPlayerFields as $field => $column) {
            if ($request->has($field)) {
                $auctionPlayerAttributes[$column] = $request->input($field);
            }
        }

        return [$playerAttributes, $auctionPlayerAttributes];
    }
}
