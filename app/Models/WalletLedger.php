<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Append-only. Never update or delete rows — post a reversing entry instead. */
#[Fillable(['id_owner', 'type', 'amount', 'available_at', 'ref_type', 'ref_id', 'idempotency_key', 'description'])]
class WalletLedger extends Model
{
    public const UPDATED_AT = null;

    public const TYPE_COLLECTION = 'collection';

    public const TYPE_REFUND = 'refund';

    public const TYPE_REFUND_REVERSAL = 'refund_reversal';

    public const TYPE_DISPUTE_HOLD = 'dispute_hold';

    public const TYPE_DISPUTE_RELEASE = 'dispute_release';

    public const TYPE_DISPUTE_LOSS = 'dispute_loss';

    public const TYPE_PAYOUT = 'payout';

    public const TYPE_PAYOUT_REVERSAL = 'payout_reversal';

    protected $table = 'wallet_ledger';

    protected function casts(): array
    {
        return [
            'id_owner' => 'integer',
            'amount' => 'integer',
            'ref_id' => 'integer',
            'available_at' => 'datetime',
        ];
    }
}
