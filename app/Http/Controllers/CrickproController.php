<?php

namespace App\Http\Controllers;

use App\Models\Auction;
use App\Models\CrickproLink;
use App\Repositories\AuctionPlayerRepository;
use App\Repositories\PlayerRepository;
use App\Repositories\TeamRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * "Import from CrickPro App" — links an auction organiser to their main
 * CrickPro account (crickpro-api-v2) with a web access code, then relays their
 * teams and players so selected squads can be copied into an auction.
 */
class CrickproController extends Controller
{
    public function __construct(
        private readonly PlayerRepository $players,
        private readonly AuctionPlayerRepository $auctionPlayers,
        private readonly TeamRepository $teams,
    ) {}

    private function base(): ?string
    {
        $url = config('services.crickpro.url');

        return $url ? rtrim($url, '/') : null;
    }

    private function linkFor(Request $request): ?CrickproLink
    {
        return CrickproLink::where('id_owner', $request->user()->id)->first();
    }

    /** Verify a crickpro user id + 8-char web access code, store the issued token. */
    public function connect(Request $request): JsonResponse
    {
        $request->validate([
            'userId' => 'required|integer',
            'accessCode' => 'required|string|size:8',
        ]);

        if (! $this->base()) {
            return response()->json(['status' => 'error', 'message' => 'CrickPro import is not configured.'], 503);
        }

        $res = Http::acceptJson()->post($this->base().'/api/user/verify-access-code', [
            'userId' => (int) $request->userId,
            'accessCode' => $request->accessCode,
        ]);

        if (! $res->successful() || ($res->json('status') !== 'success')) {
            return response()->json([
                'status' => 'error',
                'message' => $res->json('message') ?: 'Could not connect to CrickPro. Check your ID and access code.',
            ], 422);
        }

        $link = CrickproLink::updateOrCreate(
            ['id_owner' => $request->user()->id],
            [
                'crickpro_user_id' => (int) $request->userId,
                'token' => $res->json('token'),
                'name' => $res->json('data.name'),
            ],
        );

        return response()->json(['status' => 'success', 'connected' => true, 'name' => $link->name]);
    }

    public function status(Request $request): JsonResponse
    {
        $link = $this->linkFor($request);

        return response()->json([
            'status' => 'success',
            // A link with no token (e.g. from access-code login) can't drive the
            // HTTP relay imports — treat it as "not connected" so the import
            // screens prompt to connect (mint a token) instead of 401-ing.
            'connected' => (bool) ($link && $link->token),
            'name' => $link?->name,
        ]);
    }

    public function disconnect(Request $request): JsonResponse
    {
        $this->linkFor($request)?->delete();

        return response()->json(['status' => 'success', 'connected' => false]);
    }

    /** Relay the linked user's teams from crickpro-api-v2. */
    public function teams(Request $request): JsonResponse
    {
        $link = $this->linkFor($request);
        if (! $link) {
            return response()->json(['status' => 'error', 'message' => 'CrickPro account not connected.'], 409);
        }

        $res = Http::withToken($link->token)->acceptJson()
            ->get($this->base().'/api/user/teams', [
                'q' => $request->query('q'),
                'page' => (int) $request->query('page', 1),
            ]);

        if ($res->status() === 401) {
            $link->delete();

            // 409 (not 401): this is the CrickPro *integration* token expiring, not
            // the auction session — a 401 would trip the app's interceptor and log
            // the user out. 409 lets the screen prompt to reconnect CrickPro.
            return response()->json(['status' => 'error', 'message' => 'CrickPro session expired. Reconnect.'], 409);
        }

        return response()->json([
            'status' => 'success',
            'teams' => $res->json('teams', []),
            'nextPage' => $res->json('nextPage'),
        ]);
    }

    /** Relay the linked user's tournaments from crickpro-api-v2 (to pick one to import teams from). */
    public function tournaments(Request $request): JsonResponse
    {
        $link = $this->linkFor($request);
        if (! $link) {
            return response()->json(['status' => 'error', 'message' => 'CrickPro account not connected.'], 409);
        }

        $res = Http::withToken($link->token)->acceptJson()
            ->get($this->base().'/api/user/tournaments', [
                'q' => $request->query('q'),
                'page' => (int) $request->query('page', 1),
            ]);

        if ($res->status() === 401) {
            $link->delete();

            // 409 (not 401): this is the CrickPro *integration* token expiring, not
            // the auction session — a 401 would trip the app's interceptor and log
            // the user out. 409 lets the screen prompt to reconnect CrickPro.
            return response()->json(['status' => 'error', 'message' => 'CrickPro session expired. Reconnect.'], 409);
        }

        return response()->json([
            'status' => 'success',
            'tournaments' => $res->json('tournaments', []),
            'nextPage' => $res->json('nextPage'),
        ]);
    }

    /** Relay a tournament's teams (id/name/short/logo path) from crickpro-api-v2. */
    public function tournamentTeams(Request $request): JsonResponse
    {
        $request->validate(['tournamentId' => 'required|integer']);

        $link = $this->linkFor($request);
        if (! $link) {
            return response()->json(['status' => 'error', 'message' => 'CrickPro account not connected.'], 409);
        }

        $res = Http::withToken($link->token)->acceptJson()
            ->get($this->base().'/api/v2/auction-import/tournament/'.((int) $request->tournamentId).'/teams');

        if ($res->status() === 401) {
            $link->delete();

            // 409 (not 401): this is the CrickPro *integration* token expiring, not
            // the auction session — a 401 would trip the app's interceptor and log
            // the user out. 409 lets the screen prompt to reconnect CrickPro.
            return response()->json(['status' => 'error', 'message' => 'CrickPro session expired. Reconnect.'], 409);
        }

        return response()->json([
            'status' => 'success',
            'teams' => $res->json('teams', []),
        ]);
    }

    /** Relay one team's players from crickpro-api-v2. */
    public function teamPlayers(Request $request): JsonResponse
    {
        $request->validate(['teamId' => 'required|integer']);

        $link = $this->linkFor($request);
        if (! $link) {
            return response()->json(['status' => 'error', 'message' => 'CrickPro account not connected.'], 409);
        }

        // Dedicated auction-import roster endpoint (role/batting/bowling + search),
        // separate from the app's own team-players endpoint.
        $res = Http::withToken($link->token)->acceptJson()
            ->get($this->base().'/api/v2/auction-import/team/players/'.((int) $request->teamId), [
                'page' => (int) $request->query('page', 1),
                'q' => $request->query('q'),
            ]);

        if ($res->status() === 401) {
            $link->delete();

            // 409 (not 401): this is the CrickPro *integration* token expiring, not
            // the auction session — a 401 would trip the app's interceptor and log
            // the user out. 409 lets the screen prompt to reconnect CrickPro.
            return response()->json(['status' => 'error', 'message' => 'CrickPro session expired. Reconnect.'], 409);
        }

        return response()->json([
            'status' => 'success',
            'players' => $res->json('players', []),
            'nextPage' => $res->json('nextPage'),
        ]);
    }

    /** Resolve a single CrickPro player by id (from a scanned QR) to a basic card. */
    public function player(Request $request, int $playerId): JsonResponse
    {
        $link = $this->linkFor($request);
        if (! $link) {
            return response()->json(['status' => 'error', 'message' => 'CrickPro account not connected.'], 409);
        }

        $res = Http::withToken($link->token)->acceptJson()
            ->get($this->base().'/api/user/card/'.$playerId);

        if ($res->status() === 401) {
            $link->delete();

            // 409 (not 401): this is the CrickPro *integration* token expiring, not
            // the auction session — a 401 would trip the app's interceptor and log
            // the user out. 409 lets the screen prompt to reconnect CrickPro.
            return response()->json(['status' => 'error', 'message' => 'CrickPro session expired. Reconnect.'], 409);
        }

        if (! $res->successful() || ! $res->json('player')) {
            return response()->json(['status' => 'error', 'message' => 'Player not found.'], 404);
        }

        return response()->json(['status' => 'success', 'player' => $res->json('player')]);
    }

    /** Global registered-player search (by name / mobile / username) via crickpro-api-v2. */
    public function searchPlayers(Request $request): JsonResponse
    {
        $request->validate([
            'q' => 'required|string|min:1',
            'searchBy' => 'required|string|in:name,mobile,username,email',
        ]);

        $link = $this->linkFor($request);
        if (! $link) {
            return response()->json(['status' => 'error', 'message' => 'CrickPro account not connected.'], 409);
        }

        $res = Http::withToken($link->token)->acceptJson()
            ->post($this->base().'/api/search/players', [
                'q' => $request->q,
                'searchBy' => $request->searchBy,
            ]);

        if ($res->status() === 401) {
            $link->delete();

            // 409 (not 401): this is the CrickPro *integration* token expiring, not
            // the auction session — a 401 would trip the app's interceptor and log
            // the user out. 409 lets the screen prompt to reconnect CrickPro.
            return response()->json(['status' => 'error', 'message' => 'CrickPro session expired. Reconnect.'], 409);
        }

        // /search/players returns a flat array of {id,name,thumb}.
        return response()->json(['status' => 'success', 'players' => $res->json()]);
    }

    /**
     * Copy the chosen CrickPro players into an auction — creates a library
     * Player per name, then attaches them (skipping any already in the pool).
     */
    public function import(Request $request, Auction $auction): JsonResponse
    {
        abort_unless($auction->id_owner === $request->user()->id, 403);

        $request->validate([
            'players' => 'required|array|min:1|max:200',
            'players.*.name' => 'required|string|min:1|max:120',
            'players.*.crickproId' => 'nullable|integer',
            'players.*.thumb' => 'nullable|string|max:500',
            'players.*.teamRole' => 'nullable|string|max:60',
            'players.*.battingHand' => 'nullable|string|max:60',
            'players.*.bowlingStyle' => 'nullable|string|max:60',
            'categoryCode' => 'nullable|string|max:24',
            'basePrice' => 'nullable|integer|min:0',
        ]);

        $ids = [];
        foreach ($request->input('players') as $p) {
            $player = $this->players->create($auction->id_owner, [
                // Source ref back to CrickPro — lets a completed auction push the
                // player into their winning team's tournament squad.
                'id_crickpro_player' => $p['crickproId'] ?? null,
                'name' => trim($p['name']),
                'photo_url' => $p['thumb'] ?? null,
                'role' => $this->mapRole($p['teamRole'] ?? null),
                'batting_style' => $this->mapBatting($p['battingHand'] ?? null),
                'bowling_style' => isset($p['bowlingStyle']) ? mb_substr(trim($p['bowlingStyle']), 0, 40) ?: null : null,
            ]);
            $ids[] = $player->id;
        }

        $imported = $this->auctionPlayers->addFromLibrary(
            $auction,
            $ids,
            $request->input('categoryCode'),
            $request->filled('basePrice') ? (int) $request->input('basePrice') : null,
        );

        return response()->json(['status' => 'success', 'imported' => $imported]);
    }

    /**
     * Seed the auction's bidding teams from chosen CrickPro tournament teams.
     * Stores the logo PATH as-is (frontend attaches the CDN); colour is auto-
     * assigned by the palette cycle. Skips any team whose name already exists.
     */
    public function importTeams(Request $request, Auction $auction): JsonResponse
    {
        abort_unless($auction->id_owner === $request->user()->id, 403);

        $request->validate([
            'tournamentId' => 'nullable|integer',
            'tournamentName' => 'nullable|string|max:160',
            'teams' => 'required|array|min:1|max:100',
            'teams.*.id' => 'nullable|integer',
            'teams.*.name' => 'required|string|min:1|max:120',
            'teams.*.short' => 'nullable|string|max:12',
            'teams.*.logo' => 'nullable|string|max:500',
        ]);

        // Record the source tournament so the auction knows which CrickPro
        // tournament its squads push back to (id + name for display).
        if ($request->filled('tournamentId')) {
            $auction->update([
                'id_crickpro_series' => (int) $request->input('tournamentId'),
                'crickpro_series_name' => $request->input('tournamentName') ?: $auction->crickpro_series_name,
            ]);
        }

        $existing = $auction->teams()->pluck('name')->map(fn ($n) => mb_strtolower($n))->all();

        $imported = 0;
        foreach ($request->input('teams') as $t) {
            $name = trim($t['name']);
            if (in_array(mb_strtolower($name), $existing, true)) {
                continue; // already in the auction
            }

            $this->teams->create($auction, [
                'name' => $name,
                // Keep the CrickPro tournament team id so the completed squad can
                // be pushed back to that team's roster.
                'idCrickproTeam' => $t['id'] ?? null,
                'shortName' => $this->shortFor($t['short'] ?? null, $name),
                'logoUrl' => $t['logo'] ?? null,
            ]);
            $existing[] = mb_strtolower($name);
            $imported++;
        }

        return response()->json(['status' => 'success', 'imported' => $imported]);
    }

    /** A tidy <=4-char short code — the given one, else initials/first letters of the name. */
    private function shortFor(?string $short, string $name): string
    {
        $s = mb_strtoupper(trim((string) $short));
        if ($s !== '') {
            return mb_substr($s, 0, 4);
        }
        $words = preg_split('/\s+/', trim($name)) ?: [];
        if (count($words) >= 2) {
            return mb_strtoupper(mb_substr($words[0], 0, 1).mb_substr($words[1], 0, 1).mb_substr($words[count($words) - 1], 0, 1));
        }

        return mb_strtoupper(mb_substr($name, 0, 3));
    }

    /** CrickPro's free-text team_role -> the auction's role enum. */
    private function mapRole(?string $raw): string
    {
        $s = mb_strtolower(trim((string) $raw));

        return match (true) {
            $s === '' => 'batter',
            str_contains($s, 'wicket') || str_contains($s, 'keeper') || $s === 'wk' => 'wicket_keeper',
            str_contains($s, 'all') => 'all_rounder',
            str_contains($s, 'bowl') => 'bowler',
            default => 'batter',
        };
    }

    /** CrickPro's batting_hand ("Left Hand Batsman") -> right_hand / left_hand. */
    private function mapBatting(?string $raw): ?string
    {
        if (! $raw) {
            return null;
        }

        return str_contains(mb_strtolower($raw), 'left') ? 'left_hand' : 'right_hand';
    }
}
