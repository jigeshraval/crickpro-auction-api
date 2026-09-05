<?php

namespace App\Http\Controllers\Auction;

use App\Http\Controllers\Controller;
use App\Models\Auction;
use App\Models\AuctionCategory;
use App\Models\AuctionInvitation;
use App\Repositories\AuctionPlayerRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * "Invite Player" — shareable self-registration links.
 *
 * The organiser generates a link (optionally pinned to a category, which grades
 * everyone who joins through it). The invitee opens the public web page and fills
 * only their identity; category + base price stay server-controlled. New joiners
 * land as `pending` for the organiser to review.
 */
class InvitationController extends Controller
{
    public function __construct(
        private readonly AuctionPlayerRepository $auctionPlayers,
    ) {}

    private function url(Auction $auction, string $token): string
    {
        $base = rtrim((string) config('services.auction_web.url'), '/');

        return "{$base}/invitation/{$auction->id}/{$token}";
    }

    /** Owner generates an invite link (optionally for a category). */
    public function store(Request $request, Auction $auction): JsonResponse
    {
        abort_unless($auction->id_owner === $request->user()->id, 403);

        $request->validate([
            'categoryCode' => 'nullable|string|max:24',
            // UTC ISO string; the client converts the admin's local pick.
            'expiresAt' => 'nullable|date|after:now',
        ]);

        $categoryCode = $request->filled('categoryCode') ? mb_strtoupper($request->input('categoryCode')) : null;
        if ($categoryCode) {
            $exists = AuctionCategory::where('id_auction', $auction->id)->where('code', $categoryCode)->exists();
            abort_unless($exists, 422, 'Unknown category.');
        }

        $invitation = AuctionInvitation::create([
            'id_auction' => $auction->id,
            'token' => Str::random(40),
            'category_code' => $categoryCode,
            'expires_at' => $request->filled('expiresAt') ? \Illuminate\Support\Carbon::parse($request->input('expiresAt')) : null,
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'status' => 'success',
            'invitation' => [
                'token' => $invitation->token,
                'categoryCode' => $invitation->category_code,
                'expiresAt' => optional($invitation->expires_at)->toISOString(),
                'url' => $this->url($auction, $invitation->token),
            ],
        ], 201);
    }

    /** Public — the web page loads the invite to show the auction + category. */
    public function show(Auction $auction, string $token): JsonResponse
    {
        $invitation = AuctionInvitation::where('id_auction', $auction->id)->where('token', $token)->first();
        if (! $invitation) {
            return response()->json(['status' => 'error', 'message' => 'This invitation link is invalid or has been revoked.'], 404);
        }
        if ($invitation->isExpired()) {
            return response()->json(['status' => 'error', 'message' => 'This invitation link has expired.'], 410);
        }

        $category = $invitation->category_code
            ? AuctionCategory::where('id_auction', $auction->id)->where('code', $invitation->category_code)->first()
            : null;

        return response()->json([
            'status' => 'success',
            'auction' => [
                'id' => $auction->id,
                'name' => $auction->name,
                'logoUrl' => $auction->logo_url,
                'status' => $auction->status,
            ],
            'category' => $category ? ['code' => $category->code, 'name' => $category->name] : null,
            'expiresAt' => optional($invitation->expires_at)->toISOString(),
        ]);
    }

    /** Public — the invitee registers themselves into the auction pool. */
    public function join(Request $request, Auction $auction, string $token): JsonResponse
    {
        $invitation = AuctionInvitation::where('id_auction', $auction->id)->where('token', $token)->first();
        if (! $invitation) {
            return response()->json(['status' => 'error', 'message' => 'This invitation link is invalid or has been revoked.'], 404);
        }
        if ($invitation->isExpired()) {
            return response()->json(['status' => 'error', 'message' => 'This invitation link has expired.'], 410);
        }

        $data = $request->validate([
            'name' => 'required|string|min:2|max:120',
            'role' => 'nullable|string|in:batter,bowler,all_rounder,wicket_keeper,wk_batter',
            'battingStyle' => 'nullable|string|in:right_hand,left_hand',
            'bowlingStyle' => 'nullable|string|max:40',
            'city' => 'nullable|string|max:80',
            'phone' => 'nullable|string|max:20',
        ]);

        $this->auctionPlayers->add(
            $auction,
            array_filter([
                'name' => trim($data['name']),
                'role' => $data['role'] ?? 'batter',
                'batting_style' => $data['battingStyle'] ?? null,
                'bowling_style' => isset($data['bowlingStyle']) ? trim($data['bowlingStyle']) ?: null : null,
                'city' => $data['city'] ?? null,
                'phone' => $data['phone'] ?? null,
            ], fn ($v) => $v !== null),
            // Category (and therefore base price) is taken from the invite, not
            // the client — the joiner can't grade themselves. status -> pending.
            array_filter(['category_code' => $invitation->category_code], fn ($v) => $v !== null),
        );

        $invitation->increment('joined_count');

        return response()->json(['status' => 'success', 'message' => "You've joined {$auction->name}."]);
    }
}
