<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Everything PlayerResource shows an owner, minus the phone number — a spectator with only the access code gets no PII. */
class PublicPlayerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'displayName' => $this->display_name,
            'photoUrl' => $this->photo_url,
            'role' => $this->role,
            'battingStyle' => $this->batting_style,
            'bowlingStyle' => $this->bowling_style,
            'nationality' => $this->nationality,
            'city' => $this->city,
            'jerseyNumber' => $this->jersey_number,
            'isCapped' => (bool) $this->is_capped,
        ];
    }
}
