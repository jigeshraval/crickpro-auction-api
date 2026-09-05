<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One subscription record for an auction team pack (per-auction). The active,
 * non-expired record is the source of truth for an auction's entitlement, which
 * is denormalised onto the auction row.
 */
#[Fillable([
    'id_user', 'id_auction', 'plan_id', 'max_teams', 'amount', 'currency',
    'transaction_id', 'source', 'store', 'starts_at', 'expires_at', 'is_active',
    'status', 'created_by', 'notes', 'metadata',
])]
class SubscriptionHistory extends Model
{
    protected $table = 'subscription_history';

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REFUNDED = 'refunded';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_DELETED = 'deleted';

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
            'amount' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_user');
    }

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class, 'id_auction');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Active + not expired. */
    public function scopeLive($query)
    {
        return $query->where('is_active', true)
            ->where('status', self::STATUS_ACTIVE)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }
}
