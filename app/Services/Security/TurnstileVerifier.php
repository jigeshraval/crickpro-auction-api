<?php

namespace App\Services\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cloudflare Turnstile server-side check (siteverify). Shares the widget used
 * by crickpro-web-v3. Requires success, the expected action, and a hostname
 * from TURNSTILE_HOSTNAMES — fails closed on any error.
 *
 * With no TURNSTILE_SECRET configured the check is skipped outside
 * production (local dev / tests); in production a missing secret rejects.
 */
class TurnstileVerifier
{
    private const URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function enabled(): bool
    {
        return (string) config('services.turnstile.secret') !== '' || app()->environment('production');
    }

    public function verify(Request $request, string $action): bool
    {
        if (! $this->enabled()) {
            return true;
        }

        $secret = (string) config('services.turnstile.secret');
        $hostnames = array_values(array_filter(array_map('trim', explode(',', (string) config('services.turnstile.hostnames')))));
        $token = $request->input('cf-turnstile-response');

        if ($secret === '' || $hostnames === [] || ! is_string($token) || $token === '' || strlen($token) > 2048) {
            return false;
        }

        try {
            $response = Http::asForm()->timeout(10)->post(self::URL, [
                'secret' => $secret,
                'response' => $token,
                'remoteip' => $request->header('CF-Connecting-IP') ?: $request->ip(),
            ]);
            if (! $response->successful()) {
                return false;
            }
            $result = $response->json();
        } catch (\Throwable $e) {
            Log::warning('turnstile.siteverify_failed', ['error' => $e->getMessage()]);

            return false;
        }

        return ($result['success'] ?? false) === true
            && ($result['action'] ?? null) === $action
            && in_array($result['hostname'] ?? null, $hostnames, true);
    }
}
