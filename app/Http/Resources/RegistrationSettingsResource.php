<?php

namespace App\Http\Resources;

use App\Models\AuctionSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AuctionSetting */
class RegistrationSettingsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'registrationMode' => $this->registration_mode ?? 'none',
            'registrationFee' => (int) $this->registration_fee,
            'feeBearer' => $this->fee_bearer ?? 'organiser',
            'manualInstructions' => $this->manual_instructions,
            'manualQrUrl' => $this->manual_qr_url,
            'currency' => 'INR',
        ];
    }
}
