<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The Dashboard feed's own projection — a listed auction is already public
 * (its access code included, same reasoning the source app documents: being
 * listed already made the code as public as the auction itself), but this
 * still isn't the full owner-facing AuctionResource — no description, no
 * lifecycle timestamps a spectator has no use for.
 */
class PublicAuctionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'venue' => $this->venue,
            'cityId' => $this->id_city !== null ? (int) $this->id_city : null,
            'stateId' => $this->id_state !== null ? (int) $this->id_state : null,
            'countryId' => $this->id_country !== null ? (int) $this->id_country : null,
            'location' => $this->location,
            'logoUrl' => $this->logo_url,
            'coverUrl' => $this->cover_url,
            'accessCode' => $this->access_code,
            'currency' => new CurrencyResource($this->currency),
            'status' => $this->status,
            'scheduledAt' => $this->scheduled_at,
            'squadMax' => $this->settings?->squad_max,
            'initialPurse' => $this->settings?->initial_purse,
            'followersCount' => (int) ($this->followers_count ?? 0),
            'teamsCount' => $this->teams_count !== null ? (int) $this->teams_count : null,
        ];
    }
}
