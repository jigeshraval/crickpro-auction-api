<?php

namespace App\Http\Resources;

use App\Models\Payout;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Payout */
class PayoutResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payoutAccountId' => $this->id_payout_account,
            'maskedAccount' => $this->account?->masked_account,
            'accountType' => $this->account?->type,
            'amount' => $this->amount,
            'fee' => $this->fee,
            'netAmount' => $this->net_amount,
            'status' => $this->status,
            'failureReason' => $this->publicFailureReason(),
            'utr' => $this->utr,
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }

    /**
     * failure_reason holds internal text (raw RazorpayX status_details, ops markers) —
     * organisers only ever see a generic, status-based message.
     */
    private function publicFailureReason(): ?string
    {
        if ($this->failure_reason === null) {
            return null;
        }

        return match ($this->status) {
            Payout::STATUS_FAILED, Payout::STATUS_REVERSED => 'The payout could not be completed. The amount has been returned to your balance.',
            default => 'Your withdrawal is being processed. This can take a little longer than usual.',
        };
    }
}
