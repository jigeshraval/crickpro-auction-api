<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates /v1/overlay/* for crickpro-auction-overlay. The overlay sends
 * X-Overlay-Signature; it must equal config('subscription.overlay_signature').
 * Same global-signature pattern crickpro-overlay uses (OVERLAY_API_SIGNATURE).
 */
class VerifyOverlaySignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('subscription.overlay_signature');
        $given = $request->header('X-Overlay-Signature');

        if (! $expected || ! $given || ! hash_equals($expected, $given)) {
            return response()->json(['status' => 'error', 'message' => 'Invalid overlay signature.'], 401);
        }

        return $next($request);
    }
}
