<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'id_auction', 'name', 'short_name', 'logo_url', 'primary_color', 'secondary_color',
    'owner_name', 'initial_purse', 'remaining_purse', 'shortcut_key', 'display_order', 'access_code',
])]
class Team extends Model
{
    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class, 'id_auction');
    }

    public function leadingFor(): HasMany
    {
        return $this->hasMany(AuctionPlayer::class, 'id_leading_team');
    }

    public function wonPlayers(): HasMany
    {
        return $this->hasMany(AuctionPlayer::class, 'id_sold_to_team');
    }
}
