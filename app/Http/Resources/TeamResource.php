<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeamResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'shortName' => $this->short_name,
            'logoUrl' => $this->logo_url,
            'primaryColor' => $this->primary_color,
            'secondaryColor' => $this->secondary_color,
            'initialPurse' => $this->initial_purse,
            'remainingPurse' => $this->remaining_purse,
            'shortcutKey' => $this->shortcut_key,
            'displayOrder' => $this->display_order,
            // Only present when the repository has run withComputedFields()
            // first — a bare Team model (fresh from a create/update) leaves
            // these null rather than lying with a zeroed default.
            'boughtCount' => $this->bought_count,
            'slotsLeft' => $this->slots_left,
            'maxBid' => $this->max_bid,
        ];
    }
}
