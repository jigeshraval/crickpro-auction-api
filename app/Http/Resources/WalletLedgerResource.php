<?php

namespace App\Http\Resources;

use App\Models\WalletLedger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WalletLedger */
class WalletLedgerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'amount' => $this->amount,
            'availableAt' => $this->available_at?->toISOString(),
            'isPending' => $this->amount > 0 && $this->available_at?->isFuture(),
            'refType' => $this->ref_type,
            'refId' => $this->ref_id,
            'description' => $this->description,
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
