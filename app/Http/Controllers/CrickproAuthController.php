<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\Auction;
use App\Models\CrickproLink;
use App\Models\User;
use App\Repositories\AuctionRepository;
use App\Repositories\AuthRepository;
use App\Repositories\TeamRepository;
use App\Services\Auth\AuthTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * "Continue with CrickPro" + "Start Auctioning" — the seamless bridge from the
 * main CrickPro app. Two entry points, one account model (PASSWORDLESS, bound to
 * the crickpro user id via CrickproLink):
 *
 *  • connect()   — app-side: exchange a handoff token for an auction session.
 *  • provision() — server-to-server (crickpro-api-v2, secret-gated): create/
 *                  connect the account, create the auction, and import the
 *                  tournament's teams in one shot, so "Start Auctioning" works
 *                  even before the Auction app is installed.
 */
class CrickproAuthController extends Controller
{
    public function __construct(
        private readonly AuthRepository $auth,
        private readonly AuthTokenService $tokens,
        private readonly AuctionRepository $auctions,
        private readonly TeamRepository $teams,
    ) {}

    private function base(): ?string
    {
        $url = config('services.crickpro.url');

        return $url ? rtrim($url, '/') : null;
    }

    /** A token'd HTTP client that forces IPv4 (host.docker.internal's IPv6 hangs). */
    private function v2(string $token)
    {
        return Http::withToken($token)
            ->withOptions(['force_ip_resolve' => 'v4'])
            ->connectTimeout(5)->timeout(20)->acceptJson();
    }

    /** App-side: exchange a crickpro-app handoff token for an auction session. */
    public function connect(Request $request): JsonResponse
    {
        $request->validate(['token' => 'required|string']);

        if (! $this->base()) {
            return response()->json(['status' => 'error', 'message' => 'CrickPro connect is not configured.'], 503);
        }

        $res = $this->v2($request->token)->get($this->base().'/api/user/auction-identity');
        if (! $res->successful() || ! $res->json('user.id')) {
            return response()->json(['status' => 'error', 'message' => 'Could not verify your CrickPro session. Try again.'], 401);
        }

        $user = $this->connectAccount(
            (int) $res->json('user.id'),
            $res->json('user.name'),
            $res->json('user.mobile'),
            $res->json('user.thumb'),
            $request->token,
        );

        $token = $this->tokens->issue($user);

        return response()->json([
            'status' => 'success',
            'user' => new UserResource($user),
            'token' => $token->plainTextToken,
        ]);
    }

    /**
     * Server-to-server (crickpro-api-v2, X-Crickpro-Signature gated): create or
     * connect the passwordless account, create the auction, seed its teams —
     * even before the Auction app is installed.
     *
     * SECURITY — two layers: the shared secret gates WHO may call this (only
     * api-v2); the handoff TOKEN carries the identity, verified back against
     * api-v2 (never trusted from the payload). The same token authorises the
     * team fetch, so a caller can only seed teams the crickpro user can see.
     */
    public function provision(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => 'required|string',
            'tournamentId' => 'nullable|integer',
            'tournamentName' => 'nullable|string|max:120',
        ]);

        if (! $this->base()) {
            return response()->json(['status' => 'error', 'message' => 'CrickPro connect is not configured.'], 503);
        }

        // Verify the handoff token → identity (trusted from crickpro-api-v2, not the caller).
        $idRes = $this->v2($data['token'])->get($this->base().'/api/user/auction-identity');
        if (! $idRes->successful() || ! $idRes->json('user.id')) {
            return response()->json(['status' => 'error', 'message' => 'Invalid or expired CrickPro session.'], 401);
        }

        $user = $this->connectAccount(
            (int) $idRes->json('user.id'),
            $idRes->json('user.name'),
            $idRes->json('user.mobile'),
            $idRes->json('user.thumb'),
            $data['token'],
        );

        $result = $this->auctions->quickStart($user->id, [
            'name' => $data['tournamentName'] ?: 'Player Auction',
        ]);
        $auction = $result['auction'];

        // Seed teams — fetched with the SAME token, so only teams the crickpro
        // user is allowed to see can be imported.
        $imported = 0;
        if (! empty($data['tournamentId'])) {
            $tRes = $this->v2($data['token'])
                ->get($this->base().'/api/v2/auction-import/tournament/'.((int) $data['tournamentId']).'/teams');
            if ($tRes->successful()) {
                $teams = collect($tRes->json('teams', []))
                    ->map(fn ($t) => ['name' => $t['name'] ?? '', 'short' => $t['short'] ?? null, 'logo' => $t['logo'] ?? null])
                    ->all();
                $imported = $this->importTeams($auction, $teams);
            }
        }

        return response()->json([
            'status' => 'success',
            'auctionId' => $auction->id,
            'accessCode' => $auction->access_code,
            'slug' => $result['slug'] ?? $auction->slug,
            'teamsImported' => $imported,
        ], 201);
    }

    /** Create or connect the passwordless account bound to the crickpro user. */
    private function connectAccount(int $cid, ?string $name, ?string $mobile, ?string $thumb, ?string $token): User
    {
        $name = $name ?: 'CrickPro User';

        // 1) Already linked → that auction account.
        $link = CrickproLink::where('crickpro_user_id', $cid)->first();
        $user = $link ? User::find($link->id_owner) : null;

        // 2) Else an existing auction account with the same mobile → connect it.
        if (! $user && $mobile) {
            $user = $this->auth->findByMobile($mobile);
        }

        // 3) Else create a passwordless account bound to the crickpro user.
        if (! $user) {
            $user = $this->auth->createOrClaimUser(null, [
                'name' => $name,
                'mobile' => $mobile,
                'profile_image' => $thumb,
                'verified' => true,
                'password' => null,
            ]);
        } elseif ($thumb && empty($user->profile_image)) {
            $user->profile_image = $thumb;
            $user->save();
        }

        // Keep a link (token only when the app handed one over; server provision has none).
        $attrs = ['crickpro_user_id' => $cid, 'name' => $name];
        if ($token) {
            $attrs['token'] = $token;
        }
        CrickproLink::updateOrCreate(['id_owner' => $user->id], $attrs);

        return $user;
    }

    /** Seed the auction's teams from the tournament (logo path as-is, colours auto). */
    private function importTeams(Auction $auction, array $teams): int
    {
        $existing = $auction->teams()->pluck('name')->map(fn ($n) => mb_strtolower($n))->all();

        $imported = 0;
        foreach ($teams as $t) {
            $name = trim($t['name']);
            if ($name === '' || in_array(mb_strtolower($name), $existing, true)) {
                continue;
            }
            $this->teams->create($auction, [
                'name' => $name,
                'shortName' => $this->shortFor($t['short'] ?? null, $name),
                'logoUrl' => $t['logo'] ?? null,
            ]);
            $existing[] = mb_strtolower($name);
            $imported++;
        }

        return $imported;
    }

    /** A tidy <=4-char short code — the given one, else initials of the name. */
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
}
