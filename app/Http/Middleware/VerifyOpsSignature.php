<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates /v1/ops/* for crickpro-admin. The admin sends X-Ops-Signature; it must
 * equal config('subscription.ops_signature'). Same lightweight shared-secret
 * pattern the overlay endpoints use — no per-user admin auth in this API.
 */
class VerifyOpsSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('subscription.ops_signature');
        $given = $request->header('X-Ops-Signature');

        if (! $expected || ! $given || ! hash_equals($expected, $given)) {
            return response()->json(['status' => 'error', 'message' => 'Invalid ops signature.'], 401);
        }

        return $next($request);
    }
}
