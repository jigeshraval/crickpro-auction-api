<?php

namespace App\Http\Controllers;

use App\Http\Requests\Player\ShowPlayerRequest;
use App\Http\Requests\Player\StorePlayerRequest;
use App\Http\Requests\Player\UpdatePlayerRequest;
use App\Http\Resources\PlayerHistoryResource;
use App\Http\Resources\PlayerResource;
use App\Models\Player;
use App\Repositories\PlayerRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlayerController extends Controller
{
    public function __construct(
        private readonly PlayerRepository $players,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $players = $this->players->paginateForOwner(
            $request->user()->id,
            $request->string('search')->value() ?: null,
            $request->string('role')->value() ?: null,
            (int) $request->integer('perPage', 25),
        );
        $players = $this->players->withHistoryStats($players);

        return response()->json([
            'status' => 'success',
            'players' => PlayerResource::collection($players),
            'meta' => [
                'page' => $players->currentPage(),
                'perPage' => $players->perPage(),
                'total' => $players->total(),
                'totalPages' => $players->lastPage(),
            ],
        ]);
    }

    public function store(StorePlayerRequest $request): JsonResponse
    {
        $player = $this->players->create($request->user()->id, $this->columns($request));

        return response()->json([
            'status' => 'success',
            'player' => new PlayerResource($player),
        ], 201);
    }

    public function update(UpdatePlayerRequest $request, Player $player): JsonResponse
    {
        $player = $this->players->update($player, $this->columns($request));

        return response()->json([
            'status' => 'success',
            'player' => new PlayerResource($player),
        ]);
    }

    public function destroy(ShowPlayerRequest $request, Player $player): JsonResponse
    {
        $this->players->delete($player);

        return response()->json(['status' => 'success']);
    }

    public function history(ShowPlayerRequest $request, Player $player): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'history' => PlayerHistoryResource::collection($this->players->history($player)),
        ]);
    }

    /** camelCase request fields → snake_case model columns, only what was actually sent. */
    private function columns(Request $request): array
    {
        $fieldsToColumns = [
            'name' => 'name', 'displayName' => 'display_name', 'role' => 'role',
            'battingStyle' => 'batting_style', 'bowlingStyle' => 'bowling_style',
            'dateOfBirth' => 'date_of_birth', 'nationality' => 'nationality', 'city' => 'city',
            'phone' => 'phone', 'jerseyNumber' => 'jersey_number', 'isCapped' => 'is_capped',
            'notes' => 'notes',
        ];

        $attributes = [];
        foreach ($fieldsToColumns as $field => $column) {
            if ($request->has($field)) {
                $attributes[$column] = $request->input($field);
            }
        }

        return $attributes;
    }
}
