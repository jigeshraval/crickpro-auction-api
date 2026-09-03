<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuctionSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'initialPurse' => $this->initial_purse,
            'squadMin' => $this->squad_min,
            'squadMax' => $this->squad_max,
            'minBasePrice' => $this->min_base_price,
            'bidIncrementMode' => $this->bid_increment_mode,
            'bidIncrementFlat' => $this->bid_increment_flat,
            'bidIncrementSlabs' => $this->bid_increment_slabs,
            'maxOverseasPerTeam' => $this->max_overseas_per_team,
            'enforceSquadMax' => (bool) $this->enforce_squad_max,
            'enforcePurse' => (bool) $this->enforce_purse,
            'enforceOverseas' => (bool) $this->enforce_overseas,
            'enforceSquadMin' => (bool) $this->enforce_squad_min,
            'allowReentry' => (bool) $this->allow_reentry,
            'allowCustomBid' => (bool) $this->allow_custom_bid,
            'playerFields' => $this->player_fields,
        ];
    }
}
