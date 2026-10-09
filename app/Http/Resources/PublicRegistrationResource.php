<?php

namespace App\Http\Resources;

use App\Models\PlayerRegistration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The player's own registration (join response AND GET status — same shape).
 * Load with ['auction.settings', 'latestPayment'].
 *
 * @mixin PlayerRegistration
 */
class PublicRegistrationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $payment = $this->latestPayment;
        $isRazorpay = $this->mode === PlayerRegistration::MODE_RAZORPAY;
        $isManual = $this->mode === PlayerRegistration::MODE_MANUAL;
        $settings = $this->auction?->settings;

        return [
            'registrationToken' => $this->public_token,
            'mode' => $this->mode,
            'registrationStatus' => $this->status,
            'auction' => [
                'id' => $this->auction?->id,
                'name' => $this->auction?->name,
                'logoUrl' => $this->auction?->logo_url,
            ],
            'playerName' => $this->payload['name'] ?? null,
            'breakdown' => $isRazorpay && $payment ? $payment->publicBreakdown() : null,
            'qr' => $isRazorpay && $payment && $payment->hasOpenQr() ? [
                'imageUrl' => $payment->qr_image_url,
                'expiresAt' => $payment->qr_expires_at?->toISOString(),
            ] : null,
            'manual' => $isManual ? [
                'amount' => $payment?->total_amount,
                'instructions' => $settings?->manual_instructions,
                'qrUrl' => $settings?->manual_qr_url,
            ] : null,
            'proof' => $isManual ? [
                'submitted' => (bool) $payment?->proof_url,
                'utr' => $payment?->utr,
                'proofUrl' => $payment?->proof_url,
            ] : null,
            'rejectReason' => $this->reject_reason,
            'actions' => [
                'canGenerateQr' => $this->resource->canGenerateQr(),
                'canUploadProof' => $isManual && $this->status === PlayerRegistration::STATUS_AWAITING_REVIEW,
            ],
        ];
    }
}
