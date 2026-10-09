<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'id_auction', 'id_invitation', 'payload', 'mode', 'status', 'id_auction_player',
    'public_token', 'reject_reason', 'flag_reason',
])]
class PlayerRegistration extends Model
{
    public const MODE_NONE = 'none';

    public const MODE_RAZORPAY = 'razorpay';

    public const MODE_MANUAL = 'manual';

    public const STATUS_AWAITING_PAYMENT = 'awaiting_payment';

    public const STATUS_AWAITING_REVIEW = 'awaiting_review';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUS_NEEDS_REVIEW = 'needs_review';

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'id_auction' => 'integer',
        ];
    }

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class, 'id_auction');
    }

    public function invitation(): BelongsTo
    {
        return $this->belongsTo(AuctionInvitation::class, 'id_invitation');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(RegistrationPayment::class, 'id_registration');
    }

    public function latestPayment(): HasOne
    {
        return $this->hasOne(RegistrationPayment::class, 'id_registration')->latestOfMany();
    }

    /** The player may (re)generate a QR: razorpay, not finished, and no live QR. */
    public function canGenerateQr(): bool
    {
        return $this->mode === self::MODE_RAZORPAY
            && in_array($this->status, [self::STATUS_AWAITING_PAYMENT, self::STATUS_EXPIRED], true)
            && ! $this->latestPayment?->hasOpenQr();
    }
}
