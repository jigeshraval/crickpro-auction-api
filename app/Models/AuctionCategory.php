<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['id_auction', 'code', 'name', 'color', 'default_base_price', 'sort_order'])]
class AuctionCategory extends Model
{
    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class, 'id_auction');
    }
}
