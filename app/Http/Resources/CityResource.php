<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'state' => $this->state?->name,
            'country' => $this->country?->name,
            'stateId' => $this->id_state,
            'countryId' => $this->id_country,
        ];
    }
}
