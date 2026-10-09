<?php

namespace App\Http\Requests\Payments;

use App\Repositories\AuctionSettingRepository;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Minimum fee is ₹50 per player (config payments.min_registration_fee = 5000)
 * in BOTH razorpay and manual mode. At ₹50 with the organiser bearing fees the
 * estimated net is ₹38.82, so no separate "net must be positive" rule is needed.
 * India-only gating happens earlier, in the payments.eligible middleware.
 *
 * Manual mode needs a way to pay: non-empty instructions (sent now, or already
 * saved when the field is omitted) OR an already-uploaded QR. Organisers who
 * want QR-only upload the QR first (POST …/registration-settings/qr), then
 * switch the mode.
 */
class UpdateRegistrationSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('auction')->id_owner === $this->user()->id;
    }

    public function rules(): array
    {
        $min = (int) config('payments.min_registration_fee');
        $max = (int) config('payments.max_registration_fee');

        return [
            'registrationMode' => 'required|string|in:none,razorpay,manual',
            'registrationFee' => "exclude_if:registrationMode,none|required|integer|min:{$min}|max:{$max}",
            'feeBearer' => 'sometimes|string|in:organiser,player',
            'manualInstructions' => 'sometimes|nullable|string|max:1000',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty() || $this->input('registrationMode') !== 'manual') {
                return;
            }

            $settings = app(AuctionSettingRepository::class)->forAuction($this->route('auction'));
            $instructions = $this->has('manualInstructions') ? $this->input('manualInstructions') : $settings->manual_instructions;

            if (trim((string) $instructions) === '' && empty($settings->manual_qr_url)) {
                $validator->errors()->add('manualInstructions', 'Add payment instructions or upload your UPI QR before switching to manual payments.');
            }
        }];
    }
}
