<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuctionInvitation extends Model
{
    protected $fillable = [
        'id_auction',
        'token',
        'category_code',
        'expires_at',
        'created_by',
        'joined_count',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class, 'id_auction');
    }
}
