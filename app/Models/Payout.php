<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['id_owner', 'id_payout_account', 'amount', 'fee', 'net_amount', 'razorpayx_payout_id', 'status', 'failure_reason', 'utr'])]
class Payout extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REVERSED = 'reversed';

    protected function casts(): array
    {
        return ['id_owner' => 'integer', 'amount' => 'integer', 'fee' => 'integer', 'net_amount' => 'integer'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(PayoutAccount::class, 'id_payout_account')->withTrashed();
    }
}
