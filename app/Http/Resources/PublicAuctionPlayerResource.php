<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Same shape as AuctionPlayerResource, nesting PublicPlayerResource instead of PlayerResource so the player's phone never reaches an unauthenticated spectator. */
class PublicAuctionPlayerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'basePrice' => $this->base_price,
            'categoryCode' => $this->category_code,
            'auctionOrder' => $this->auction_order,
            'customFieldValues' => $this->custom_field_values,
            'roundNumber' => $this->round_number,
            'isOverseas' => (bool) $this->is_overseas,
            'status' => $this->status,
            'currentBid' => $this->current_bid,
            'leadingTeamId' => $this->id_leading_team,
            'soldToTeamId' => $this->id_sold_to_team,
            'soldPrice' => $this->sold_price,
            'bidCount' => $this->bid_count,
            'player' => new PublicPlayerResource($this->whenLoaded('player')),
        ];
    }
}
