<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'id_auction', 'id_auction_player', 'id_team', 'bid_amount',
    'sequence_number', 'is_retracted', 'id_placed_by_user',
])]
class Bid extends Model
{
    protected function casts(): array
    {
        return [
            'is_retracted' => 'boolean',
        ];
    }

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class, 'id_auction');
    }

    public function auctionPlayer(): BelongsTo
    {
        return $this->belongsTo(AuctionPlayer::class, 'id_auction_player');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'id_team');
    }
}
