<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the server-to-server "Start Auctioning" provision call from
 * crickpro-api-v2. The caller sends X-Crickpro-Signature; it must equal
 * config('services.crickpro.provision_secret'). Same lightweight shared-secret
 * pattern as VerifyOpsSignature.
 */
class VerifyCrickproSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('services.crickpro.provision_secret');
        $given = $request->header('X-Crickpro-Signature');

        if (! $expected || ! $given || ! hash_equals($expected, $given)) {
            return response()->json(['status' => 'error', 'message' => 'Invalid CrickPro signature.'], 401);
        }

        return $next($request);
    }
}
