<?php

namespace App\Http\Requests\Auction;

use Illuminate\Foundation\Http\FormRequest;

class SelectPlayerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('auction')->id_owner === $this->user()->id;
    }

    public function rules(): array
    {
        return [
            'auctionPlayerId' => 'nullable|integer',
            'force' => 'nullable|boolean',
        ];
    }
}
