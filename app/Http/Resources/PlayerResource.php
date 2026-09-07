<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlayerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'displayName' => $this->display_name,
            'photoUrl' => $this->photo_url,
            'role' => $this->role,
            'roleTypeId' => $this->id_role_type,
            'roleType' => $this->whenLoaded('roleType', fn () => $this->roleType ? ['id' => $this->roleType->id, 'name' => $this->roleType->name, 'short' => $this->roleType->short] : null),
            'battingStyle' => $this->batting_style,
            'bowlingStyle' => $this->bowling_style,
            'dateOfBirth' => $this->date_of_birth,
            'nationality' => $this->nationality,
            'city' => $this->city,
            'phone' => $this->phone,
            'jerseyNumber' => $this->jersey_number,
            'isCapped' => (bool) $this->is_capped,
            'notes' => $this->notes,
            // Only present when the repository has run withHistoryStats() first.
            'historyCount' => $this->history_count,
            'bestSoldPrice' => $this->best_sold_price,
        ];
    }
}
