<?php

namespace App\Http\Requests\Auction;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAuctionSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('auction')->id_owner === $this->user()->id;
    }

    public function rules(): array
    {
        return [
            'initialPurse' => 'sometimes|integer|min:0',
            'squadMin' => 'sometimes|integer|min:0',
            'squadMax' => 'sometimes|integer|min:1|max:50',
            'minBasePrice' => 'sometimes|integer|min:0',
            'bidIncrementMode' => 'sometimes|string|in:flat,slab',
            'bidIncrementFlat' => 'sometimes|integer|min:1',
            'bidIncrementSlabs' => 'sometimes|nullable|array',
            'bidIncrementSlabs.*.upTo' => 'required_with:bidIncrementSlabs|integer|min:0',
            'bidIncrementSlabs.*.increment' => 'required_with:bidIncrementSlabs|integer|min:1',
            'maxOverseasPerTeam' => 'sometimes|nullable|integer|min:0',
            'enforceSquadMax' => 'sometimes|boolean',
            'enforcePurse' => 'sometimes|boolean',
            'enforceOverseas' => 'sometimes|boolean',
            'enforceSquadMin' => 'sometimes|boolean',
            'allowReentry' => 'sometimes|boolean',
            'allowCustomBid' => 'sometimes|boolean',
            'playerFields' => 'sometimes|nullable|array|max:6',
            'playerFields.*.key' => 'required_with:playerFields|string|max:40',
            'playerFields.*.label' => 'required_with:playerFields|string|max:60',
            'playerFields.*.options' => 'required_with:playerFields|array',
            'playerFields.*.options.*' => 'string|max:60',
        ];
    }
}
