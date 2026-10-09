<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Organiser view of one payment (Collections + refund response). Load with 'registration'. */
class RegistrationPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $payload = $this->registration?->payload ?? [];

        return [
            'id' => $this->id,
            'registrationId' => $this->id_registration,
            'auctionId' => $this->id_auction,
            'player' => [
                'name' => $payload['name'] ?? null,
                'phone' => $payload['phone'] ?? null,
                'auctionPlayerId' => $this->registration?->id_auction_player,
            ],
            'provider' => $this->provider,
            'feeBearer' => $this->fee_bearer,
            'baseAmount' => $this->base_amount,
            'platformFee' => $this->platform_fee,
            'gatewayFee' => $this->gateway_fee,
            'gatewayTax' => $this->gateway_tax,
            'totalAmount' => $this->total_amount,
            'organiserNet' => $this->organiser_net,
            'refundedAmount' => $this->refunded_amount,
            'organiserRefunded' => $this->organiser_refunded,
            'refundableAmount' => $this->resource->refundableAmount(),
            'status' => $this->status,
            'isDuplicate' => (bool) $this->is_duplicate,
            'razorpayPaymentId' => $this->razorpay_payment_id,
            'utr' => $this->utr,
            'proofUrl' => $this->proof_url,
            'paidAt' => $this->paid_at?->toISOString(),
            'availableAt' => $this->available_at?->toISOString(),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
