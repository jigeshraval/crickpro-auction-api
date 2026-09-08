<?php

namespace App\Services;

use App\Models\Auction;
use App\Models\AuctionPlayer;

/**
 * Builds the AuctionOverlayState the overlay renders — the single source of
 * truth shared by the REST overlay feed (OverlayController::state) and the
 * device-side MQTT publisher (the app fetches this via a control/state response
 * and publishes it to `auction/{id}/state`; auction-api NEVER touches MQTT).
 */
class AuctionOverlayState
{
    /** @return array<string,mixed> */
    public function build(Auction $auction): array
    {
        $auction->loadMissing(['currency', 'settings', 'categories', 'teams']);
        $settings = $auction->settings;
        $squadMax = (int) ($settings->squad_max ?? 0);

        // Bought counts per team (sold players).
        $bought = AuctionPlayer::where('id_auction', $auction->id)
            ->where('status', 'sold')
            ->selectRaw('id_sold_to_team, count(*) as c')
            ->groupBy('id_sold_to_team')
            ->pluck('c', 'id_sold_to_team');

        // Players per category (for the set banner).
        $catCounts = AuctionPlayer::where('id_auction', $auction->id)
            ->selectRaw('category_code, count(*) as c')
            ->groupBy('category_code')
            ->pluck('c', 'category_code');

        // Current player — only when one is ACTIVELY on the block (selected/bidding);
        // a stale pointer to a sold/pending player shows the intro instead.
        $current = null;
        if ($auction->id_current_auction_player) {
            $cp = AuctionPlayer::with('player.roleType')->find($auction->id_current_auction_player);
            if ($cp && $cp->player && in_array($cp->status, ['selected', 'bidding'], true)) {
                $p = $cp->player;
                $current = [
                    'id' => $cp->id,
                    'name' => $p->name,
                    'photoUrl' => $p->photo_url,
                    'role' => $p->role,
                    'roleType' => $p->roleType?->name,
                    'age' => $p->date_of_birth ? $p->date_of_birth->age : null,
                    'country' => $p->nationality,
                    'prevTeam' => null,
                    'isOverseas' => (bool) $cp->is_overseas,
                    'isCapped' => (bool) $p->is_capped,
                    'categoryCode' => $cp->category_code,
                    'basePrice' => (int) $cp->base_price,
                    'currentBid' => $cp->current_bid !== null ? (int) $cp->current_bid : null,
                    'leadingTeamId' => $cp->id_leading_team,
                    'status' => $cp->status,
                ];
            }
        }

        $teams = $auction->teams->map(fn ($t) => [
            'id' => $t->id,
            'name' => $t->name,
            'shortName' => $t->short_name,
            'logoUrl' => $t->logo_url,
            'color' => $t->primary_color,
            'purseRemaining' => (int) $t->remaining_purse,
            'boughtCount' => (int) ($bought[$t->id] ?? 0),
            'slotsLeft' => $squadMax > 0 ? max(0, $squadMax - (int) ($bought[$t->id] ?? 0)) : null,
        ])->values();

        $sold = AuctionPlayer::with('player.roleType')->where('id_auction', $auction->id)->where('status', 'sold')->get()
            ->map(fn ($ap) => [
                'playerId' => $ap->id,
                'name' => $ap->player?->name,
                'teamId' => $ap->id_sold_to_team,
                'price' => (int) $ap->sold_price,
                'photoUrl' => $ap->player?->photo_url,
                'role' => $ap->player?->role,
                'roleType' => $ap->player?->roleType?->name,
                'categoryCode' => $ap->category_code,
            ])
            ->values();

        $unsold = AuctionPlayer::with('player')->where('id_auction', $auction->id)->where('status', 'unsold')->get()
            ->map(fn ($ap) => ['playerId' => $ap->id, 'name' => $ap->player?->name])
            ->values();

        return [
            'auction' => [
                'name' => $auction->name,
                'logoUrl' => $auction->logo_url,
                'currencySymbol' => $auction->currency?->symbol ?? '₹',
                'status' => $auction->status,
                'round' => (int) ($auction->current_round ?? 1),
                'imageRounded' => (bool) ($auction->image_rounded ?? false),
            ],
            'settings' => [
                'squadMin' => (int) ($settings->squad_min ?? 0),
                'squadMax' => $squadMax,
                'maxOverseasPerTeam' => $settings->max_overseas_per_team ?? null,
            ],
            'categories' => $auction->categories->sortBy('sort_order')->map(fn ($c) => [
                'code' => $c->code,
                'name' => $c->name,
                'color' => $c->color,
                'sortOrder' => (int) $c->sort_order,
                'basePrice' => (int) $c->default_base_price,
                'playerCount' => (int) ($catCounts[$c->code] ?? 0),
            ])->values(),
            'currentPlayer' => $current,
            'teams' => $teams,
            'sold' => $sold,
            'unsold' => $unsold,
            'branding' => ['standbyCopy' => null],
        ];
    }
}
