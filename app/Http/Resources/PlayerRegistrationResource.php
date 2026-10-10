<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Organiser view of a registration (Approvals). Load with 'latestPayment'. */
class PlayerRegistrationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $payload = $this->payload ?? [];
        $payment = $this->latestPayment;

        return [
            'id' => $this->id,
            'mode' => $this->mode,
            'status' => $this->status,
            'player' => [
                'name' => $payload['name'] ?? null,
                'role' => $payload['role'] ?? null,
                'battingStyle' => $payload['battingStyle'] ?? null,
                'bowlingStyle' => $payload['bowlingStyle'] ?? null,
                'city' => $payload['city'] ?? null,
                'phone' => $payload['phone'] ?? null,
                'photoUrl' => $payload['photoUrl'] ?? null,
            ],
            'amount' => $payment?->total_amount,
            'paymentId' => $payment?->id,
            'paymentStatus' => $payment?->status,
            // Razorpay registrations prove payment with the gateway transaction id, not a screenshot.
            'razorpayPaymentId' => $payment?->razorpay_payment_id,
            'utr' => $payment?->utr,
            'proofUrl' => $payment?->proof_url,
            'rejectReason' => $this->reject_reason,
            'flagReason' => $this->flag_reason,
            'auctionPlayerId' => $this->id_auction_player,
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}
