<?php

namespace App\Http\Controllers;

use App\Http\Resources\SubscriptionHistoryResource;
use App\Models\Auction;
use App\Services\Payments\SubscriptionPaymentsResolver;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function __construct(private readonly SubscriptionService $subs) {}

    /**
     * The team-pack catalogue, ascending by team count.
     *
     * For clients that can't read the store's own offering — the web build has no
     * billing SDK — so the subscription screen shows the same tiers this API
     * enforces rather than a hardcoded copy. The native apps use RevenueCat.
     */
    public function plans(Request $request): JsonResponse
    {
        $resolved = SubscriptionPaymentsResolver::forUser($request->user());

        return response()->json([
            'status' => 'success',
            'data' => $this->subs->teamPacks($resolved['currency']),
            'currency' => $resolved['currency'],
            'currencySymbol' => SubscriptionPaymentsResolver::symbol($resolved['currency']),
            'gateway' => $resolved['gateway'],
            'freeTeams' => (int) config('subscription.free_teams', 2),
        ]);
    }

    /**
     * Start a web purchase of a team pack for an auction. Resolves the buyer's
     * country → gateway: Razorpay returns a hosted payment-link `shortUrl` (the
     * payment_link.paid webhook activates it); disabled countries (Pakistan) get
     * `{gateway:"whatsapp"}` so the client hands them to support instead.
     */
    public function checkout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'auctionId' => 'required|integer|exists:auctions,id',
            'teams' => 'required|integer',
            // Web users sign in by phone, so many have no email. Razorpay requires
            // one on international (non-INR) links, so the client collects it when
            // missing. Persisted below so receipts + later checkouts have it.
            'email' => 'nullable|email',
        ]);

        $user = $request->user();
        $auction = Auction::find($data['auctionId']);
        if ($auction->id_owner !== $user->id) {
            return response()->json(['status' => 'error', 'message' => 'Not your auction.'], 403);
        }

        // Keep an existing account email; only fill one the user just supplied.
        if (! empty($data['email']) && empty($user->email)) {
            try {
                $user->forceFill(['email' => $data['email']])->save();
            } catch (\Throwable $e) {
                // A unique-email clash shouldn't block checkout — still use it on the link.
            }
        }
        $effectiveEmail = $user->email ?: ($data['email'] ?? null);

        $resolved = SubscriptionPaymentsResolver::forUser($user);
        if ($resolved['gateway'] === SubscriptionPaymentsResolver::GATEWAY_WHATSAPP) {
            return response()->json(['status' => 'success', 'gateway' => 'whatsapp']);
        }

        // International (non-INR) links require a customer email; make the need explicit.
        if ($resolved['currency'] !== 'INR' && empty($effectiveEmail)) {
            return response()->json(['status' => 'error', 'code' => 'EMAIL_REQUIRED', 'message' => 'Please add an email to continue.'], 422);
        }

        try {
            $link = $this->subs->createRazorpayCheckout($user, $auction, (int) $data['teams'], $resolved['currency'], $effectiveEmail);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['status' => 'error', 'message' => 'Could not start checkout. Please try again.'], 502);
        }

        return response()->json([
            'status' => 'success',
            'gateway' => 'razorpay',
            'shortUrl' => $link['shortUrl'],
            'referenceId' => $link['referenceId'],
        ]);
    }

    /**
     * Per-auction entitlement for the signed-in user. `auctionId` optional — when
     * given, returns that auction's team allowance + overlay access.
     */
    public function status(Request $request): JsonResponse
    {
        $auctionId = $request->integer('auctionId') ?: null;
        $maxTeams = 0;
        $isSubscribed = false;
        $teamCount = 0;
        $allowance = (int) config('subscription.free_teams', 2);
        $allowed = true;

        if ($auctionId) {
            $active = $this->subs->activeForAuction($auctionId);
            $maxTeams = $active?->max_teams ?? 0;
            $isSubscribed = (bool) $active;

            $auction = Auction::find($auctionId);
            if ($auction) {
                $allowance = $this->subs->allowanceFor($auction);
                $teamCount = $auction->teams()->count();
                // `allowed` is the REAL gate — the plan must cover the team count,
                // not merely exist. `isSubscribed` alone is misleading.
                $allowed = $teamCount <= $allowance;
            }
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'isSubscribed' => $isSubscribed,
                'maxTeams' => (int) $maxTeams,
                'freeTeams' => (int) config('subscription.free_teams', 2),
                'teamCount' => (int) $teamCount,
                'allowance' => (int) $allowance,
                'allowed' => (bool) $allowed,
            ],
        ]);
    }

    /** Client-side purchase confirmation (RevenueCat). Idempotent on transactionId. */
    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate([
            'productId' => 'required|string|max:100',
            'transactionId' => 'nullable|string|max:255',
            'auctionId' => 'nullable|integer|exists:auctions,id',
            'teams' => 'nullable|integer',
            'store' => 'nullable|string|max:50',
        ]);

        // Only the owner can attach a purchase to their auction.
        if (! empty($data['auctionId'])) {
            $auction = Auction::find($data['auctionId']);
            if ($auction && $auction->id_owner !== $request->user()->id) {
                return response()->json(['status' => 'error', 'message' => 'Not your auction.'], 403);
            }
        }

        $sub = $this->subs->confirm(
            $request->user()->id,
            $data['auctionId'] ?? null,
            $data['productId'],
            $data['transactionId'] ?? null,
            $data['store'] ?? 'play_store',
        );

        return response()->json([
            'status' => 'success',
            'subscription' => new SubscriptionHistoryResource($sub),
        ], 201);
    }
}
