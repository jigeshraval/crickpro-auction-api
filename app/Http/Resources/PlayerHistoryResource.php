<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One auction-player row, as seen from a library player's "History" tab. */
class PlayerHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'auctionPlayerId' => $this->id,
            'auctionId' => $this->id_auction,
            'auctionName' => $this->auction?->name,
            'status' => $this->status,
            'basePrice' => $this->base_price,
            'soldPrice' => $this->sold_price,
            'soldToTeamName' => $this->soldToTeam?->name,
            'soldAt' => $this->sold_at,
        ];
    }
}
