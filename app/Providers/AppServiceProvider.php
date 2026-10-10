<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Player payment-status polling (every 3 s). Keyed per registration so a
        // venue full of players behind one shared Wi-Fi / carrier IP isn't throttled;
        // the per-IP cap only stops abuse.
        RateLimiter::for('registration-status', fn (Request $request) => [
            Limit::perMinute(40)->by('reg-status:'.$request->route('registrationToken')),
            Limit::perMinute(1200)->by('reg-status-ip:'.$request->ip()),
        ]);
    }
}
