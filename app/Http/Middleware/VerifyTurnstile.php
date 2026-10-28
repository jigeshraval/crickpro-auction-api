<?php

namespace App\Http\Middleware;

use App\Services\Security\TurnstileVerifier;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bot gate for the auth endpoints. Enforced ONLY for the web client
 * (`platform: web`) — the native apps send `platform: ios|android`, carry no
 * Turnstile widget, and are left to the `auth.throttle` limiter + store
 * attestation. The expected Turnstile action is the middleware parameter, e.g.
 * `turnstile:auction_auth`.
 *
 * The web sends the token in the `cf-turnstile-response` header (TurnstileVerifier
 * reads header or body). Fails closed with 403 `captcha_failed`.
 */
class VerifyTurnstile
{
    public function __construct(private readonly TurnstileVerifier $turnstile) {}

    public function handle(Request $request, Closure $next, string $action): Response
    {
        if (strtolower((string) $request->header('platform')) !== 'web') {
            return $next($request);
        }

        if (! $this->turnstile->verify($request, $action)) {
            return response()->json([
                'status' => 'error',
                'code' => 'captcha_failed',
                'message' => 'Security check failed. Please try again.',
            ], 403);
        }

        return $next($request);
    }
}
