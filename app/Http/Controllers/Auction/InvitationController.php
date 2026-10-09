<?php

namespace App\Http\Controllers\Auction;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicRegistrationResource;
use App\Models\Auction;
use App\Models\AuctionCategory;
use App\Models\AuctionInvitation;
use App\Models\PlayerRegistration;
use App\Repositories\AuctionPlayerRepository;
use App\Repositories\AuctionSettingRepository;
use App\Services\Payments\FeeCalculator;
use App\Services\Payments\RegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * "Invite Player" — shareable self-registration links.
 *
 * The organiser generates a link (optionally pinned to a category, which grades
 * everyone who joins through it). The invitee opens the public web page and fills
 * only their identity; category + base price stay server-controlled.
 *
 * Registration payments (spec 2026-10-09): when the auction charges a fee, join
 * creates a PlayerRegistration instead of adding directly — razorpay mode returns
 * a UPI QR, manual mode returns the organiser's instructions. The player is added
 * to the pool only once payment is confirmed / the proof is approved.
 */
class InvitationController extends Controller
{
    public function __construct(
        private readonly AuctionPlayerRepository $auctionPlayers,
        private readonly AuctionSettingRepository $settings,
        private readonly RegistrationService $registrations,
        private readonly FeeCalculator $fees,
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
            'expires_at' => $request->filled('expiresAt') ? Carbon::parse($request->input('expiresAt')) : null,
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

    /** Public — the web page loads the invite to show the auction + category + fee. */
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
            'registration' => $this->registrationInfo($auction),
        ]);
    }

    /** Public — the invitee registers themselves (directly, or via payment/review). */
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

        $settings = $this->settings->forAuction($auction);

        if (RegistrationService::effectiveMode($settings) === PlayerRegistration::MODE_NONE) {
            $this->auctionPlayers->add(
                $auction,
                RegistrationService::playerAttributes($data),
                // Category (and therefore base price) is taken from the invite, not
                // the client — the joiner can't grade themselves. status -> pending.
                array_filter(['category_code' => $invitation->category_code], fn ($v) => $v !== null),
            );

            $invitation->increment('joined_count');

            return response()->json(['status' => 'success', 'mode' => 'none', 'message' => "You've joined {$auction->name}."]);
        }

        $registration = $this->registrations->start($auction, $invitation, $settings, $data);

        return response()->json([
            'status' => 'success',
            ...(new PublicRegistrationResource($registration))->resolve($request),
        ], 201);
    }

    private function registrationInfo(Auction $auction): array
    {
        $settings = $this->settings->forAuction($auction);
        $mode = RegistrationService::effectiveMode($settings);

        return [
            'mode' => $mode,
            'fee' => $mode === PlayerRegistration::MODE_NONE ? 0 : (int) $settings->registration_fee,
            'breakdown' => $mode === PlayerRegistration::MODE_RAZORPAY
                ? $this->fees->breakdown((int) $settings->registration_fee, (string) $settings->fee_bearer)->toPublicArray()
                : null,
        ];
    }
}
