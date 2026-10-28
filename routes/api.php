<?php

use App\Http\Controllers\Auction\AuctionCategoryController;
use App\Http\Controllers\Auction\AuctionControlController;
use App\Http\Controllers\Auction\AuctionController;
use App\Http\Controllers\Auction\AuctionFollowController;
use App\Http\Controllers\Auction\AuctionLiveViewController;
use App\Http\Controllers\Auction\AuctionPlayerController;
use App\Http\Controllers\Auction\AuctionSettingsController;
use App\Http\Controllers\Auction\InvitationController;
use App\Http\Controllers\Auction\OverlayController;
use App\Http\Controllers\Auction\PublicAuctionController;
use App\Http\Controllers\Auction\TeamController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\CityController;
use App\Http\Controllers\CrickproAuthController;
use App\Http\Controllers\CrickproController;
use App\Http\Controllers\Ops\OpsSubscriptionController;
use App\Http\Controllers\Ops\OpsUserController;
use App\Http\Controllers\Payments\CollectionController;
use App\Http\Controllers\Payments\PayoutAccountController;
use App\Http\Controllers\Payments\PublicRegistrationController;
use App\Http\Controllers\Payments\RazorpayWebhookController;
use App\Http\Controllers\Payments\RefundController;
use App\Http\Controllers\Payments\RegistrationApprovalController;
use App\Http\Controllers\Payments\RegistrationSettingsController;
use App\Http\Controllers\Payments\WalletController;
use App\Http\Controllers\Payments\WithdrawalController;
use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\PlayerController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\UploadController;
use App\Http\Controllers\WhatsAppWebhookController;
use App\Models\RoleType;
use Illuminate\Support\Facades\Route;

// Meta calls these directly (GET handshake, POST inbound messages) — no
// Sanctum auth, no auth.throttle group. Protected by its own signature
// check (verify()'s hub_verify_token / handle()'s X-Hub-Signature-256) and
// ApiGateway's "whatsapp-webhook" rate limit instead.
Route::prefix('v1/webhook/whatsapp')->group(function () {
    Route::get('/', [WhatsAppWebhookController::class, 'verify']);
    Route::post('/', [WhatsAppWebhookController::class, 'handle']);
});

Route::prefix('v1/auth')->group(function () {
    Route::post('check-user', [AuthController::class, 'checkUser'])->middleware('auth.throttle:check-user');
    Route::post('check-user-mobile', [AuthController::class, 'checkUserMobile'])->middleware('auth.throttle:check-user-mobile');

    // `turnstile:auction_auth` runs after the throttle (so a flood 429s before
    // any CF siteverify call) and ONLY for the web client — see VerifyTurnstile.
    Route::post('send-email-otp', [AuthController::class, 'sendEmailOtp'])->middleware(['auth.throttle:send-email-otp', 'turnstile:auction_auth']);
    Route::post('verify-email-otp', [AuthController::class, 'verifyEmailOtp'])->middleware('auth.throttle:verify-email-otp');

    Route::post('send-whatsapp-otp', [AuthController::class, 'sendWhatsappOtp'])->middleware(['auth.throttle:send-whatsapp-otp', 'turnstile:auction_auth']);
    Route::post('verify-whatsapp-otp', [AuthController::class, 'verifyWhatsappOtp'])->middleware('auth.throttle:verify-whatsapp-otp');

    Route::post('login-password', [AuthController::class, 'loginPassword'])->middleware(['auth.throttle:login-password', 'turnstile:auction_auth']);
    Route::post('login-password-mobile', [AuthController::class, 'loginPasswordMobile'])->middleware(['auth.throttle:login-password-mobile', 'turnstile:auction_auth']);

    Route::post('register', [AuthController::class, 'register'])->middleware(['auth.throttle:register', 'turnstile:auction_auth']);

    // Seamless "Continue with CrickPro" — exchange a crickpro-app handoff token
    // for an auction session (creates/connects a passwordless account). No
    // Turnstile: the handoff token is the credential and the web reaches this
    // only via a crickpro-app hand-off (no widget on that path).
    Route::post('crickpro', [CrickproAuthController::class, 'connect'])->middleware('auth.throttle:login-password');

    // "CrickPro Connect" — log in with a CrickPro User Id + Web Access Code
    // (validated against crickpro-api-v2), creating/connecting the account.
    Route::post('crickpro-connect', [CrickproAuthController::class, 'connectWithAccessCode'])->middleware(['auth.throttle:login-password', 'turnstile:auction_auth']);

    Route::post('verify-reset-otp-mobile', [AuthController::class, 'verifyResetOtpMobile'])->middleware('auth.throttle:verify-reset-otp-mobile');
    Route::post('reset-password-mobile', [AuthController::class, 'resetPasswordMobile'])->middleware(['auth.throttle:reset-password-mobile', 'turnstile:auction_auth']);
    Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware(['auth.throttle:reset-password', 'turnstile:auction_auth']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('user', [AuthController::class, 'user']);
        Route::patch('user', [AuthController::class, 'updateUser']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::delete('account', [AuthController::class, 'deleteAccount']);
    });
});

// No auth:sanctum — "Location (public)" the same way crickpro-api's own
// GET /v3/cities is; this is a local copy of that data, not a live call to it.
Route::get('v1/cities', [CityController::class, 'search']);

// No auth:sanctum — reachable by anyone holding the access code, not just the
// auction's owner. Throttled since the code itself is the only credential.
Route::prefix('v1/public/auctions')->middleware('throttle:30,1')->group(function () {
    Route::post('lookup', [PublicAuctionController::class, 'lookup']);
    Route::get('{code}/teams', [PublicAuctionController::class, 'teams']);
    Route::get('{code}/players', [PublicAuctionController::class, 'players']);
    Route::get('{code}/state', [PublicAuctionController::class, 'state']);
});

// Public "Invite Player" self-registration — token in the URL is the credential.
// Per IP; raised for venues where many players share one IP.
Route::prefix('v1/public/invitations')->middleware('throttle:120,1')->group(function () {
    Route::get('{auction}/{token}', [InvitationController::class, 'show']);
    Route::post('{auction}/{token}/join', [InvitationController::class, 'join']);
});

// Public registration status / payment — the registration token is the credential.
// GET is polled every 3 s by the player page (venue NAT → generous limit).
Route::prefix('v1/public/registrations/{registrationToken}')->group(function () {
    Route::get('/', [PublicRegistrationController::class, 'show'])->middleware('throttle:registration-status');
    Route::post('new-qr', [PublicRegistrationController::class, 'newQr'])->middleware('throttle:10,1');
    Route::post('proof', [PublicRegistrationController::class, 'proof'])->middleware('throttle:10,1');
});

// Spectator live view (crickpro-app /fixture/{id}/view parity) — any auction by
// id, login optional. Own limit: venue viewers share one NAT IP.
Route::get('v1/auctions/{auction}/view/live', [AuctionLiveViewController::class, 'show'])->middleware('throttle:240,1');

Route::prefix('v1/auctions')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [AuctionController::class, 'index']);
    Route::get('public', [AuctionController::class, 'publicIndex']);
    Route::post('quick-start', [AuctionController::class, 'quickStart']);
    Route::get('{auction}', [AuctionController::class, 'show']);
    Route::patch('{auction}', [AuctionController::class, 'update']);
    Route::delete('{auction}', [AuctionController::class, 'destroy']);

    Route::post('{auction}/start', [AuctionController::class, 'start']);
    Route::post('{auction}/pause', [AuctionController::class, 'pause']);
    Route::post('{auction}/resume', [AuctionController::class, 'resume']);
    Route::post('{auction}/complete', [AuctionController::class, 'complete']);
    Route::post('{auction}/reset', [AuctionController::class, 'reset']);
    // Push the completed squads back into the linked CrickPro tournament.
    Route::post('{auction}/push-crickpro', [AuctionController::class, 'pushToCrickpro']);
    Route::get('{auction}/overlay-link', [AuctionController::class, 'overlayLink']);
    Route::get('{auction}/overlay-theme', [AuctionController::class, 'overlayTheme']);
    Route::put('{auction}/overlay-theme', [AuctionController::class, 'saveOverlayTheme']);
    Route::post('{auction}/follow', [AuctionFollowController::class, 'store']);
    Route::delete('{auction}/follow', [AuctionFollowController::class, 'destroy']);

    Route::get('{auction}/state', [AuctionControlController::class, 'state']);
    Route::post('{auction}/control/select-player', [AuctionControlController::class, 'selectPlayer']);
    Route::post('{auction}/control/next-player', [AuctionControlController::class, 'nextPlayer']);
    Route::post('{auction}/control/open-bidding', [AuctionControlController::class, 'openBidding']);
    Route::post('{auction}/control/stop-bidding', [AuctionControlController::class, 'stopBidding']);
    Route::post('{auction}/control/next-round', [AuctionControlController::class, 'nextRound']);
    Route::post('{auction}/control/bid', [AuctionControlController::class, 'bid']);
    Route::post('{auction}/control/sold', [AuctionControlController::class, 'sold']);
    Route::post('{auction}/control/unsold', [AuctionControlController::class, 'unsold']);
    Route::post('{auction}/control/return-to-pool', [AuctionControlController::class, 'returnToPool']);
    Route::post('{auction}/control/undo', [AuctionControlController::class, 'undo']);

    Route::get('{auction}/settings', [AuctionSettingsController::class, 'show']);
    Route::put('{auction}/settings', [AuctionSettingsController::class, 'update']);

    // Registration payments (owner) — spec §1/§2. India-only (payments.eligible).
    Route::middleware('payments.eligible')->group(function () {
        Route::get('{auction}/registration-settings', [RegistrationSettingsController::class, 'show']);
        Route::put('{auction}/registration-settings', [RegistrationSettingsController::class, 'update']);
        Route::post('{auction}/registration-settings/qr', [RegistrationSettingsController::class, 'uploadQr']);
        Route::get('{auction}/fee-preview', [RegistrationSettingsController::class, 'feePreview']);
    });

    Route::get('{auction}/registrations', [RegistrationApprovalController::class, 'index']);
    Route::post('{auction}/registrations/{registration}/approve', [RegistrationApprovalController::class, 'approve']);
    Route::post('{auction}/registrations/{registration}/reject', [RegistrationApprovalController::class, 'reject']);
    Route::get('{auction}/collections', [CollectionController::class, 'index']);

    Route::get('{auction}/teams', [TeamController::class, 'index']);
    Route::post('{auction}/teams', [TeamController::class, 'store']);
    Route::patch('{auction}/teams/{team}', [TeamController::class, 'update']);
    Route::delete('{auction}/teams/{team}', [TeamController::class, 'destroy']);
    Route::post('{auction}/teams/{team}/logo', [UploadController::class, 'teamLogo']);

    Route::get('{auction}/categories', [AuctionCategoryController::class, 'index']);
    Route::put('{auction}/categories', [AuctionCategoryController::class, 'sync']);

    Route::get('{auction}/players', [AuctionPlayerController::class, 'index']);
    Route::get('{auction}/players/{auctionPlayer}', [AuctionPlayerController::class, 'show']);
    Route::post('{auction}/players', [AuctionPlayerController::class, 'store']);
    Route::patch('{auction}/players/{auctionPlayer}', [AuctionPlayerController::class, 'update']);
    Route::delete('{auction}/players/{auctionPlayer}', [AuctionPlayerController::class, 'destroy']);
    Route::put('{auction}/players/reorder', [AuctionPlayerController::class, 'reorder']);
    Route::post('{auction}/players/shuffle', [AuctionPlayerController::class, 'shuffle']);
    Route::post('{auction}/players/bulk', [AuctionPlayerController::class, 'bulkAdd']);
    Route::post('{auction}/players/generate-sets', [AuctionPlayerController::class, 'generateSets']);
    Route::post('{auction}/players/from-library', [AuctionPlayerController::class, 'addFromLibrary']);
    Route::post('{auction}/players/import-crickpro', [CrickproController::class, 'import']);
    Route::post('{auction}/teams/import-crickpro', [CrickproController::class, 'importTeams']);
    Route::post('{auction}/invitations', [InvitationController::class, 'store']);

    Route::post('{auction}/cover', [UploadController::class, 'auctionCover']);
    Route::post('{auction}/logo', [UploadController::class, 'auctionLogo']);
});

// The caller's followed auctions (server-side follows, newest first).
Route::get('v1/me/following', [AuctionFollowController::class, 'index'])->middleware('auth:sanctum');

// Organiser wallet (registration collections → RazorpayX withdrawals).
Route::prefix('v1/wallet')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [WalletController::class, 'show']);
    Route::get('ledger', [WalletController::class, 'ledger']);
    Route::get('payout-accounts', [PayoutAccountController::class, 'index']);
    Route::post('payout-accounts', [PayoutAccountController::class, 'store'])->middleware('throttle:10,1');
    Route::delete('payout-accounts/{payoutAccount}', [PayoutAccountController::class, 'destroy']);
    Route::get('withdrawals', [WithdrawalController::class, 'index']);
    Route::post('withdrawals', [WithdrawalController::class, 'store'])->middleware('throttle:10,1');
});

// Organiser refunds a registration payment (fees included for the player).
Route::post('v1/payments/{payment}/refund', [RefundController::class, 'store'])->middleware(['auth:sanctum', 'throttle:20,1']);
// Fee preview before an auction exists (Create Auction form) — India-only, checked in the controller.
Route::get('v1/payments/fee-preview', [RegistrationSettingsController::class, 'userFeePreview'])->middleware(['auth:sanctum', 'throttle:120,1']);

Route::prefix('v1/integrations/crickpro')->middleware('auth:sanctum')->group(function () {
    Route::get('status', [CrickproController::class, 'status']);
    Route::post('connect', [CrickproController::class, 'connect']);
    Route::post('disconnect', [CrickproController::class, 'disconnect']);
    Route::get('teams', [CrickproController::class, 'teams']);
    Route::get('tournaments', [CrickproController::class, 'tournaments']);
    // Server-to-server "Start Auctioning" (crickpro-api-v2, X-Crickpro-Signature).
    Route::post('provision', [CrickproAuthController::class, 'provision'])
        ->withoutMiddleware('auth:sanctum')->middleware('crickpro.signature');
    Route::get('tournament-teams', [CrickproController::class, 'tournamentTeams']);
    Route::get('team-players', [CrickproController::class, 'teamPlayers']);
    Route::post('search-players', [CrickproController::class, 'searchPlayers']);
    Route::get('player/{playerId}', [CrickproController::class, 'player']);
});

// Player role types — static reference for registration / add-player forms (public).
Route::get('v1/role-types', fn () => response()->json(['status' => 'success', 'roleTypes' => RoleType::orderBy('sort_order')->get(['id', 'name', 'short'])]));

Route::prefix('v1/players')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [PlayerController::class, 'index']);
    Route::post('/', [PlayerController::class, 'store']);
    Route::get('{player}/history', [PlayerController::class, 'history']);
    Route::patch('{player}', [PlayerController::class, 'update']);
    Route::delete('{player}', [PlayerController::class, 'destroy']);
    Route::post('{player}/photo', [UploadController::class, 'playerPhoto']);
});

// App-facing subscription: per-auction entitlement + store purchase confirm.
Route::prefix('v1/subscription')->middleware('auth:sanctum')->group(function () {
    Route::get('plans', [SubscriptionController::class, 'plans']);
    Route::get('status', [SubscriptionController::class, 'status']);
    Route::post('confirm', [SubscriptionController::class, 'confirm']);
    // Web Razorpay Payment Link checkout (native apps use RevenueCat/confirm).
    Route::post('checkout', [SubscriptionController::class, 'checkout']);
    // Browser-return activation: verify the signed params Razorpay appends to the
    // callback_url and activate — a webhook-independent path (rides the buyer's tab).
    Route::post('verify-return', [SubscriptionController::class, 'verifyReturn']);
});

// Overlay feed for crickpro-auction-overlay — crickpro-overlay parity: global
// X-Overlay-Signature on the group, per-auction 8-char secret validated once.
Route::prefix('v1/overlay')->middleware(['overlay.signature', 'throttle:240,1'])->group(function () {
    Route::put('auction/validate/secret', [OverlayController::class, 'validateSecret']);
    Route::get('auction/{auction}/theme-variant', [OverlayController::class, 'theme']);
    Route::get('auction/{auction}/view', [OverlayController::class, 'state']);
});

// RevenueCat webhook — the entitlement source of truth (bearer-token gated).
Route::post('v1/webhook/revenue-cat', [PaymentWebhookController::class, 'revenueCat']);

// Razorpay (PG) + RazorpayX webhooks — HMAC-signed raw body (X-Razorpay-Signature),
// no Sanctum. Own IP rate limit; Razorpay retries on non-2xx.
Route::prefix('v1/webhook')->middleware('throttle:1200,1')->group(function () {
    Route::post('razorpay', [RazorpayWebhookController::class, 'gateway']);
    Route::post('razorpayx', [RazorpayWebhookController::class, 'payouts']);
});

// crickpro-admin subscription management (X-Ops-Signature gated).
Route::prefix('v1/ops')->middleware('ops.signature')->group(function () {
    Route::get('users', [OpsUserController::class, 'index']);
    Route::get('subscriptions', [OpsSubscriptionController::class, 'index']);
    Route::post('subscriptions', [OpsSubscriptionController::class, 'store']);
    Route::delete('subscriptions/{id}', [OpsSubscriptionController::class, 'destroy']);
});
