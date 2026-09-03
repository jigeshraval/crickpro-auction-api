<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'id_auction', 'id_player', 'base_price', 'category_code', 'auction_order',
    'custom_field_values', 'round_number', 'is_overseas', 'status', 'current_bid',
    'id_leading_team', 'id_sold_to_team', 'sold_price', 'bid_count',
    'nominated_at', 'sold_at', 'unsold_at',
])]
class AuctionPlayer extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SELECTED = 'selected';

    public const STATUS_BIDDING = 'bidding';

    public const STATUS_SOLD = 'sold';

    public const STATUS_UNSOLD = 'unsold';

    public const STATUS_WITHDRAWN = 'withdrawn';

    protected function casts(): array
    {
        return [
            'custom_field_values' => 'array',
            'is_overseas' => 'boolean',
            'nominated_at' => 'datetime',
            'sold_at' => 'datetime',
            'unsold_at' => 'datetime',
        ];
    }

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class, 'id_auction');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'id_player');
    }

    public function leadingTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'id_leading_team');
    }

    public function soldToTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'id_sold_to_team');
    }
}
