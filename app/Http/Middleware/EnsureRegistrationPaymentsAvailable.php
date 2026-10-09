<?php

namespace App\Http\Middleware;

use App\Models\Auction;
use App\Services\Payments\RegistrationPaymentsEligibility;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 403 on registration-payment settings for auctions that aren't India + INR.
 * Ownership is checked FIRST so a non-owner gets the generic 403 and learns
 * nothing about someone else's auction (country/currency) from the message.
 */
class EnsureRegistrationPaymentsAvailable
{
    public function handle(Request $request, Closure $next): Response
    {
        $auction = $request->route('auction');
        if (! $auction instanceof Auction) {
            $auction = Auction::find($auction);
        }

        if ($auction && (int) $auction->id_owner !== (int) $request->user()?->id) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden.'], 403);
        }

        if ($auction && ! RegistrationPaymentsEligibility::forAuction($auction)) {
            return response()->json(['status' => 'error', 'message' => RegistrationPaymentsEligibility::MESSAGE], 403);
        }

        return $next($request);
    }
}
