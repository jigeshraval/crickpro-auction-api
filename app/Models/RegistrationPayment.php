<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'id_registration', 'id_auction', 'id_owner', 'provider', 'fee_bearer', 'razorpay_qr_id', 'qr_image_url',
    'qr_expires_at', 'razorpay_payment_id', 'base_amount', 'platform_fee', 'gateway_fee', 'gateway_tax',
    'total_amount', 'organiser_net', 'refunded_amount', 'organiser_refunded', 'platform_fee_refunded',
    'gateway_fee_refunded', 'status', 'paid_at', 'available_at', 'proof_url', 'utr', 'is_duplicate',
])]
class RegistrationPayment extends Model
{
    public const PROVIDER_RAZORPAY = 'razorpay';

    public const PROVIDER_MANUAL = 'manual';

    public const STATUS_CREATED = 'created';

    public const STATUS_PAID = 'paid';

    public const STATUS_PARTIALLY_REFUNDED = 'partially_refunded';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUS_DISPUTED = 'disputed';

    public const STATUS_EXPIRED = 'expired';

    /** Terminal: a chargeback was lost (review 2, I2). */
    public const STATUS_DISPUTE_LOST = 'dispute_lost';

    /** Statuses that represent money actually received (collections list). */
    public const COLLECTED_STATUSES = [self::STATUS_PAID, self::STATUS_PARTIALLY_REFUNDED, self::STATUS_REFUNDED, self::STATUS_DISPUTED, self::STATUS_DISPUTE_LOST];

    protected function casts(): array
    {
        return [
            'id_owner' => 'integer',
            'id_auction' => 'integer',
            'base_amount' => 'integer',
            'platform_fee' => 'integer',
            'gateway_fee' => 'integer',
            'gateway_tax' => 'integer',
            'total_amount' => 'integer',
            'organiser_net' => 'integer',
            'refunded_amount' => 'integer',
            'organiser_refunded' => 'integer',
            'platform_fee_refunded' => 'integer',
            'gateway_fee_refunded' => 'integer',
            'qr_expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'available_at' => 'datetime',
            'is_duplicate' => 'boolean',
        ];
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(PlayerRegistration::class, 'id_registration');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(PaymentRefund::class, 'id_payment');
    }

    public function disputes(): HasMany
    {
        return $this->hasMany(PaymentDispute::class, 'id_payment');
    }

    public function hasOpenQr(): bool
    {
        return $this->status === self::STATUS_CREATED
            && $this->razorpay_qr_id !== null
            && $this->qr_expires_at !== null
            && $this->qr_expires_at->isFuture();
    }

    public function refundableAmount(): int
    {
        if ($this->provider !== self::PROVIDER_RAZORPAY
            || ! in_array($this->status, [self::STATUS_PAID, self::STATUS_PARTIALLY_REFUNDED], true)) {
            return 0;
        }

        return max(0, $this->total_amount - $this->refunded_amount);
    }

    /** disputed / dispute_lost: refund bookkeeping must not overwrite these statuses. */
    public function isDisputeStatus(): bool
    {
        return in_array($this->status, [self::STATUS_DISPUTED, self::STATUS_DISPUTE_LOST], true);
    }

    /** paid / partially_refunded / refunded from the refund counters. */
    public function settledStatus(): string
    {
        if ($this->refunded_amount >= $this->total_amount) {
            return self::STATUS_REFUNDED;
        }

        return $this->refunded_amount > 0 ? self::STATUS_PARTIALLY_REFUNDED : self::STATUS_PAID;
    }

    /** What the player is shown — same keys as FeeBreakdown::toPublicArray(). */
    public function publicBreakdown(): array
    {
        return [
            'baseAmount' => $this->base_amount,
            'platformFee' => $this->platform_fee,
            'gatewayFee' => $this->fee_bearer === 'player' ? $this->total_amount - $this->base_amount - $this->platform_fee : 0,
            'totalAmount' => $this->total_amount,
            'feeBearer' => $this->fee_bearer,
            'currency' => 'INR',
        ];
    }
}
