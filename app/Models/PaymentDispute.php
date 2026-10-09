<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['id_payment', 'razorpay_dispute_id', 'amount', 'organiser_hold', 'status'])]
class PaymentDispute extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_WON = 'won';

    public const STATUS_LOST = 'lost';

    public const STATUS_CLOSED = 'closed';

    protected function casts(): array
    {
        return ['amount' => 'integer', 'organiser_hold' => 'integer'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(RegistrationPayment::class, 'id_payment');
    }
}
