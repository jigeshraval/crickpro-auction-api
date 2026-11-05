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

    /**
     * Stream the auction's full player list as CSV (opens in Excel). Owner-gated
     * by ShowAuctionRequest; additionally gated by the ops-granted `exportPlayers`
     * feature so hiding the button isn't the only control. Money is emitted in
     * major units (stored minor / 100).
     */
    public function export(ShowAuctionRequest $request, Auction $auction): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        abort_unless($request->user()->hasFeature('exportPlayers'), 403, 'Player export is not enabled for your account.');

        $players = AuctionPlayer::where('id_auction', $auction->id)
            ->with(['player', 'soldToTeam'])
            ->orderBy('auction_order')
            ->orderBy('id')
            ->get();

        $major = fn ($minor) => $minor === null ? '' : number_format(((float) $minor) / 100, 2, '.', '');
        $filename = 'players-'.($auction->slug ?: $auction->id).'.csv';

        return response()->streamDownload(function () use ($players, $major) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Order', 'Name', 'Role', 'Overseas', 'Category', 'Base Price', 'Status', 'Sold To', 'Sold Price', 'Bids', 'Nationality', 'City', 'Jersey', 'Capped']);
            foreach ($players as $p) {
                $pl = $p->player;
                fputcsv($out, [
                    $p->auction_order,
                    $pl?->display_name ?: ($pl?->name ?? ''),
                    $pl?->role ? str_replace('_', ' ', (string) $pl->role) : '',
                    $p->is_overseas ? 'Yes' : 'No',
                    $p->category_code,
                    $major($p->base_price),
                    $p->status,
                    $p->soldToTeam?->name ?? '',
                    $major($p->sold_price),
                    $p->bid_count,
                    $pl?->nationality,
                    $pl?->city,
                    $pl?->jersey_number,
                    $pl?->is_capped ? 'Yes' : 'No',
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
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

    public function generateSets(ShowAuctionRequest $request, Auction $auction): JsonResponse
    {
        $assigned = $this->auctionPlayers->generateSetsFromRoles($auction);

        return response()->json(['status' => 'success', 'assigned' => $assigned]);
    }

    public function addFromLibrary(AddPlayersFromLibraryRequest $request, Auction $auction): JsonResponse
    {
        $attached = $this->auctionPlayers->addFromLibrary(
            $auction,
            $request->input('playerIds'),
            $request->input('categoryCode'),
            $request->filled('basePrice') ? (int) $request->input('basePrice') : null,
        );

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
