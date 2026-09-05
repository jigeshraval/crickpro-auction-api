<?php

namespace App\Http\Controllers\Auction;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auction\DeleteTeamRequest;
use App\Http\Requests\Auction\ShowAuctionRequest;
use App\Http\Requests\Auction\StoreTeamRequest;
use App\Http\Requests\Auction\UpdateTeamRequest;
use App\Http\Resources\TeamResource;
use App\Models\Auction;
use App\Models\Team;
use App\Repositories\TeamRepository;
use Illuminate\Http\JsonResponse;

class TeamController extends Controller
{
    public function __construct(
        private readonly TeamRepository $teams,
    ) {}

    public function index(ShowAuctionRequest $request, Auction $auction): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'teams' => TeamResource::collection($this->teams->listForAuction($auction)),
        ]);
    }

    public function store(StoreTeamRequest $request, Auction $auction): JsonResponse
    {
        // Enforce the plan's team allowance (free baseline, raised by an active
        // pack). The app gates this too, but the server is the real guard.
        $allowance = max((int) config('subscription.free_teams', 3), (int) ($auction->max_teams ?? 0));
        if ($auction->teams()->count() >= $allowance) {
            return response()->json([
                'status' => 'error',
                'code' => 'team_limit',
                'message' => "This auction allows up to {$allowance} teams. Buy a bigger team pack to add more.",
                'allowance' => $allowance,
            ], 402);
        }

        $team = $this->teams->create($auction, $request->validated());

        return response()->json([
            'status' => 'success',
            'team' => new TeamResource($team),
        ], 201);
    }

    public function update(UpdateTeamRequest $request, Auction $auction, Team $team): JsonResponse
    {
        $attributes = [];
        if ($request->has('name')) {
            $attributes['name'] = $request->input('name');
        }
        if ($request->has('shortName')) {
            $attributes['short_name'] = mb_strtoupper($request->input('shortName'));
        }
        if ($request->filled('primaryColor')) {
            $attributes['primary_color'] = strtoupper($request->input('primaryColor'));
        }

        $team = $this->teams->update($team, $attributes);

        return response()->json([
            'status' => 'success',
            'team' => new TeamResource($team),
        ]);
    }

    public function destroy(DeleteTeamRequest $request, Auction $auction, Team $team): JsonResponse
    {
        if ($this->teams->hasBoughtPlayers($team)) {
            return response()->json([
                'status' => 'error',
                'message' => 'This team has bought players — remove or reassign them first.',
            ], 422);
        }

        $this->teams->delete($team);

        return response()->json(['status' => 'success']);
    }
}
