<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['id_payment', 'razorpay_refund_id', 'amount', 'organiser_debit', 'platform_portion', 'gateway_portion', 'status', 'initiated_by'])]
class PaymentRefund extends Model
{
    public const STATUS_CREATED = 'created';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_FAILED = 'failed';

    public const BY_ORGANISER = 'organiser';

    public const BY_EXTERNAL = 'external';

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'organiser_debit' => 'integer',
            'platform_portion' => 'integer',
            'gateway_portion' => 'integer',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(RegistrationPayment::class, 'id_payment');
    }
}
