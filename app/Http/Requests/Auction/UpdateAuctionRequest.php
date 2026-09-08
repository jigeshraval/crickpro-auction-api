<?php

namespace App\Http\Requests\Auction;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Covers the Create Auction screen's Private-visibility follow-up (quick-start
 * can't set isListed, only PATCH can) and the Settings screen's identity card
 * (venue, scheduled date/time, listing toggle). Cover/logo images go through
 * UploadController instead — this request only ever carries plain fields.
 */
class UpdateAuctionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('auction')->id_owner === $this->user()->id;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|min:2|max:150',
            'currencyId' => 'sometimes|integer|exists:currencies,id',
            'isListed' => 'sometimes|boolean',
            'imageRounded' => 'sometimes|boolean',
            'venue' => 'sometimes|nullable|string|max:200',
            'scheduledAt' => 'sometimes|nullable|date',
            'seasonLabel' => 'sometimes|nullable|string|max:60',
        ];
    }
}
