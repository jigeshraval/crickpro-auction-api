<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Reference table mirrored 1:1 (same ids) from each frontend's bundled src/lib/currencies.ts. */
#[Fillable(['code', 'name', 'symbol'])]
class Currency extends Model
{
    public function auctions(): HasMany
    {
        return $this->hasMany(Auction::class, 'id_currency');
    }
}
