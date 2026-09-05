<?php

namespace App\Http\Requests\Auction;

use Illuminate\Foundation\Http\FormRequest;

class AddPlayersFromLibraryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('auction')->id_owner === $this->user()->id;
    }

    public function rules(): array
    {
        return [
            'playerIds' => 'required|array|min:1',
            'playerIds.*' => 'integer|exists:players,id',
            'categoryCode' => 'nullable|string|max:24',
            'basePrice' => 'nullable|integer|min:0',
        ];
    }
}
