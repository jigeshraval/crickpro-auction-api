<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'id_owner', 'type', 'contact_id', 'fund_account_id', 'validation_id', 'masked_account', 'ifsc',
    'holder_name', 'verified_name', 'status', 'failure_reason', 'is_default',
])]
class PayoutAccount extends Model
{
    use SoftDeletes;

    public const TYPE_BANK = 'bank';

    public const TYPE_VPA = 'vpa';

    public const STATUS_VALIDATING = 'validating';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return ['id_owner' => 'integer', 'is_default' => 'boolean'];
    }
}
