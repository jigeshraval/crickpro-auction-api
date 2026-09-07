<?php

namespace App\Http\Controllers\Auction;

use App\Http\Controllers\Controller;
use App\Models\Auction;
use App\Services\AuctionOverlayState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public overlay feed for crickpro-auction-overlay. Gated by the auction's
 * 8-char overlay_secret (X-Auction-Key header or ?key). Returns the full
 * AuctionOverlayState the overlay renders, and the chosen theme.
 */
class OverlayController extends Controller
{
    /**
     * Validate the per-auction 8-char secret (crickpro-overlay's
     * PUT /match/validate/overlay/secret equivalent). The route itself is gated
     * by the global X-Overlay-Signature; this checks the auction-specific key.
     */
    public function validateSecret(Request $request): JsonResponse
    {
        $data = $request->validate([
            'auctionId' => 'required|integer',
            'secretKey' => 'required|string',
        ]);

        $auction = Auction::find($data['auctionId']);
        $verified = $auction && $auction->overlay_secret && hash_equals($auction->overlay_secret, $data['secretKey']);

        return response()->json(['verified' => (bool) $verified]);
    }

    /** Theme-variant ({ dir, config }) — crickpro-overlay parity. Signature-gated route. */
    public function theme(Request $request, Auction $auction): JsonResponse
    {
        // `config.colors` (operator overrides) recolors the base Ganesha tokens.
        $config = $auction->overlay_theme ?: null;

        return response()->json(['status' => 'success', 'data' => ['dir' => 'ganesha', 'config' => $config]]);
    }

    /** The overlay state snapshot (crickpro-overlay's /fixture/{id}/view equivalent). */
    public function state(Request $request, Auction $auction, AuctionOverlayState $builder): JsonResponse
    {
        return response()->json(['status' => 'success', 'data' => $builder->build($auction)]);
    }
}
