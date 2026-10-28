<?php

use App\Http\Middleware\AuthThrottler;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'auth.throttle' => AuthThrottler::class,
            'ops.signature' => \App\Http\Middleware\VerifyOpsSignature::class,
            'overlay.signature' => \App\Http\Middleware\VerifyOverlaySignature::class,
            'crickpro.signature' => \App\Http\Middleware\VerifyCrickproSignature::class,
            'payments.eligible' => \App\Http\Middleware\EnsureRegistrationPaymentsAvailable::class,
            'turnstile' => \App\Http\Middleware\VerifyTurnstile::class,
        ]);

        // Trust Cloudflare as the only proxy in front of the origin, so
        // `request()->ip()` resolves the real client (not the CF edge) from
        // X-Forwarded-For. Pinned to Cloudflare's published ranges — not '*' —
        // so a direct-to-origin attacker can't spoof the forwarded headers.
        // (ipAddress() additionally prefers CF-Connecting-IP.)
        // Source: https://www.cloudflare.com/ips/ — refreshed 2026-10-25.
        $middleware->trustProxies(at: [
            // IPv4
            '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
            '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
            '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
            '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
            // IPv6
            '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
            '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
        ], headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO);

        // Pure JSON API — there is no 'login' route to redirect guests to.
        // Without this, an unauthenticated request that doesn't send
        // `Accept: application/json` crashes with RouteNotFoundException
        // instead of returning 401 (Authenticate::redirectTo() otherwise
        // tries route('login') by default).
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Report unhandled exceptions to Sentry.
        Integration::handles($exceptions);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('v1/*') || $request->expectsJson(),
        );
    })->create();
