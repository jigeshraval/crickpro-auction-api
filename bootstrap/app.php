<?php

use App\Http\Middleware\AuthThrottler;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

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
        ]);

        // Pure JSON API — there is no 'login' route to redirect guests to.
        // Without this, an unauthenticated request that doesn't send
        // `Accept: application/json` crashes with RouteNotFoundException
        // instead of returning 401 (Authenticate::redirectTo() otherwise
        // tries route('login') by default).
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('v1/*') || $request->expectsJson(),
        );
    })->create();
