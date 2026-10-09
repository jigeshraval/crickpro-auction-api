<?php

namespace App\Http\Resources;

use App\Models\PayoutAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PayoutAccount */
class PayoutAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'maskedAccount' => $this->masked_account,
            'ifsc' => $this->ifsc,
            'holderName' => $this->holder_name,
            'verifiedName' => $this->verified_name,
            'status' => $this->status,
            'failureReason' => $this->failure_reason,
            'isDefault' => (bool) $this->is_default,
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
