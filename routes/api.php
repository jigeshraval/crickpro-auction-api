<?php

use App\Http\Controllers\Auction\AuctionCategoryController;
use App\Http\Controllers\Auction\InvitationController;
use App\Http\Controllers\CrickproAuthController;
use App\Http\Controllers\CrickproController;
use App\Http\Controllers\Auction\AuctionControlController;
use App\Http\Controllers\Auction\AuctionController;
use App\Http\Controllers\Auction\AuctionPlayerController;
use App\Http\Controllers\Auction\AuctionSettingsController;
use App\Http\Controllers\Auction\OverlayController;
use App\Http\Controllers\Auction\PublicAuctionController;
use App\Http\Controllers\Auction\TeamController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\CityController;
use App\Http\Controllers\Ops\OpsSubscriptionController;
use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\PlayerController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\UploadController;
use App\Http\Controllers\WhatsAppWebhookController;
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

    Route::post('send-email-otp', [AuthController::class, 'sendEmailOtp'])->middleware('auth.throttle:send-email-otp');
    Route::post('verify-email-otp', [AuthController::class, 'verifyEmailOtp'])->middleware('auth.throttle:verify-email-otp');

    Route::post('send-whatsapp-otp', [AuthController::class, 'sendWhatsappOtp'])->middleware('auth.throttle:send-whatsapp-otp');
    Route::post('verify-whatsapp-otp', [AuthController::class, 'verifyWhatsappOtp'])->middleware('auth.throttle:verify-whatsapp-otp');

    Route::post('login-password', [AuthController::class, 'loginPassword'])->middleware('auth.throttle:login-password');
    Route::post('login-password-mobile', [AuthController::class, 'loginPasswordMobile'])->middleware('auth.throttle:login-password-mobile');

    Route::post('register', [AuthController::class, 'register'])->middleware('auth.throttle:register');

    // Seamless "Continue with CrickPro" — exchange a crickpro-app handoff token
    // for an auction session (creates/connects a passwordless account).
    Route::post('crickpro', [CrickproAuthController::class, 'connect'])->middleware('auth.throttle:login-password');

    // "CrickPro Connect" — log in with a CrickPro User Id + Web Access Code
    // (validated against crickpro-api-v2), creating/connecting the account.
    Route::post('crickpro-connect', [CrickproAuthController::class, 'connectWithAccessCode'])->middleware('auth.throttle:login-password');

    Route::post('verify-reset-otp-mobile', [AuthController::class, 'verifyResetOtpMobile'])->middleware('auth.throttle:verify-reset-otp-mobile');
    Route::post('reset-password-mobile', [AuthController::class, 'resetPasswordMobile'])->middleware('auth.throttle:reset-password-mobile');
    Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('auth.throttle:reset-password');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('user', [AuthController::class, 'user']);
        Route::post('logout', [AuthController::class, 'logout']);
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
Route::prefix('v1/public/invitations')->middleware('throttle:20,1')->group(function () {
    Route::get('{auction}/{token}', [InvitationController::class, 'show']);
    Route::post('{auction}/{token}/join', [InvitationController::class, 'join']);
});

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
Route::get('v1/role-types', fn () => response()->json(['status' => 'success', 'roleTypes' => \App\Models\RoleType::orderBy('sort_order')->get(['id', 'name', 'short'])]));

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
    Route::get('status', [SubscriptionController::class, 'status']);
    Route::post('confirm', [SubscriptionController::class, 'confirm']);
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

// crickpro-admin subscription management (X-Ops-Signature gated).
Route::prefix('v1/ops')->middleware('ops.signature')->group(function () {
    Route::get('users', [\App\Http\Controllers\Ops\OpsUserController::class, 'index']);
    Route::get('subscriptions', [OpsSubscriptionController::class, 'index']);
    Route::post('subscriptions', [OpsSubscriptionController::class, 'store']);
    Route::delete('subscriptions/{id}', [OpsSubscriptionController::class, 'destroy']);
});
