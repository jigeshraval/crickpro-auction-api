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
            // Where Razorpay redirects after payment (signed params → verify-return).
            // Validated against an allowlist below; dev passes its localhost origin.
            'returnUrl' => 'nullable|url',
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

        // INR → a UPI QR shown inline (scan & pay, no hosted page / no email).
        if ($resolved['currency'] === 'INR') {
            try {
                $qr = $this->subs->createRazorpayQr($user, $auction, (int) $data['teams']);

                return response()->json([
                    'status' => 'success',
                    'gateway' => 'razorpay',
                    'mode' => 'qr',
                    'qrImageUrl' => $qr['qrImageUrl'],
                    'referenceId' => $qr['referenceId'],
                    'amount' => $qr['amount'],
                    'currency' => 'INR',
                ]);
            } catch (\App\Services\Payments\RazorpayException $e) {
                // UPI QR unavailable (test-mode, or a Razorpay 500) — don't dead-end
                // the buyer: fall through to a hosted Payment Link for INR too.
                report($e);
            } catch (\InvalidArgumentException|\RuntimeException $e) {
                // Unknown pack / no price — a real client error, not a QR hiccup.
                return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
            }
        }

        // International (non-INR) links require a customer email; make the need explicit.
        // (INR falling back from QR skips this — no email needed for an INR link.)
        if ($resolved['currency'] !== 'INR' && empty($effectiveEmail)) {
            return response()->json(['status' => 'error', 'code' => 'EMAIL_REQUIRED', 'message' => 'Please add an email to continue.'], 422);
        }

        try {
            $callbackUrl = $this->safeReturnUrl($data['returnUrl'] ?? null);
            $link = $this->subs->createRazorpayCheckout($user, $auction, (int) $data['teams'], $resolved['currency'], $effectiveEmail, $callbackUrl);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['status' => 'error', 'message' => 'Could not start checkout. Please try again.'], 502);
        }

        return response()->json([
            'status' => 'success',
            'gateway' => 'razorpay',
            'mode' => 'link',
            'shortUrl' => $link['shortUrl'],
            'referenceId' => $link['referenceId'],
        ]);
    }

    /**
     * The signed-in user's own purchase history (newest first), paginated. Feeds
     * the web/app "Purchase history" screens; invoices are rendered client-side
     * from these rows. Excludes soft-deleted rows.
     */
    public function history(Request $request): JsonResponse
    {
        $perPage = min(50, max(1, (int) $request->integer('perPage', 20)));

        $rows = \App\Models\SubscriptionHistory::query()
            ->where('id_user', $request->user()->id)
            // Hide soft-deleted + abandoned/failed checkout attempts (e.g. a QR row
            // cancelled when it fell back to a link).
            ->whereNotIn('status', [\App\Models\SubscriptionHistory::STATUS_DELETED, \App\Models\SubscriptionHistory::STATUS_CANCELLED])
            ->orderByDesc('id')
            ->simplePaginate($perPage);

        return response()->json([
            'status' => 'success',
            ...paginated(SubscriptionHistoryResource::collection($rows), 'subscriptions', true),
        ]);
    }

    /**
     * Activate from the signed params Razorpay appended to the callback_url
     * (browser return). Webhook-independent — the buyer's own redirect proves
     * payment. The signature is verified in the service; no auth-owner check is
     * needed because only a valid Razorpay signature can activate.
     */
    public function verifyReturn(Request $request): JsonResponse
    {
        $data = $request->validate([
            'razorpay_payment_link_id' => 'nullable|string',
            'razorpay_payment_link_reference_id' => 'required|string',
            'razorpay_payment_link_status' => 'nullable|string',
            'razorpay_payment_id' => 'nullable|string',
            'razorpay_signature' => 'required|string',
        ]);

        $result = $this->subs->activateFromReturn($data);

        $ok = $result['status'] === 'active';

        return response()->json([
            'status' => $ok ? 'success' : 'error',
            'result' => $result['status'], // active | not_paid | invalid | unknown
            'auctionId' => $result['auctionId'],
        ], $ok ? 200 : ($result['status'] === 'invalid' ? 422 : 200));
    }

    /**
     * Only allow a redirect back to the configured web app or localhost (dev).
     * Anything else falls through to the server-configured callback (null here).
     */
    private function safeReturnUrl(?string $url): ?string
    {
        if (! $url) {
            return null;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $webHost = strtolower((string) parse_url((string) config('services.auction_web.url'), PHP_URL_HOST));
        $allowed = array_filter([$webHost, 'localhost', '127.0.0.1']);

        return in_array($host, $allowed, true) ? $url : null;
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
            // Actively settle a pending razorpay purchase against Razorpay before
            // reading entitlement — so the client's status poll completes a QR/link
            // payment itself (like registration), not waiting on the webhook.
            $this->subs->pollPendingForAuction($request->user()->id, $auctionId);

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
