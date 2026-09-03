<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'id_auction', 'initial_purse', 'squad_min', 'squad_max', 'min_base_price',
    'bid_increment_mode', 'bid_increment_flat', 'bid_increment_slabs', 'max_overseas_per_team',
    'enforce_squad_max', 'enforce_purse', 'enforce_overseas', 'enforce_squad_min',
    'allow_reentry', 'allow_custom_bid', 'player_fields',
])]
class AuctionSetting extends Model
{
    public const BID_INCREMENT_FLAT = 'flat';

    public const BID_INCREMENT_SLAB = 'slab';

    protected function casts(): array
    {
        return [
            'bid_increment_slabs' => 'array',
            'player_fields' => 'array',
            'enforce_squad_max' => 'boolean',
            'enforce_purse' => 'boolean',
            'enforce_overseas' => 'boolean',
            'enforce_squad_min' => 'boolean',
            'allow_reentry' => 'boolean',
            'allow_custom_bid' => 'boolean',
        ];
    }

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class, 'id_auction');
    }
}
