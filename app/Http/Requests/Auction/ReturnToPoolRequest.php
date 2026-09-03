<?php

namespace App\Http\Requests\Auction;

use Illuminate\Foundation\Http\FormRequest;

class ReturnToPoolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('auction')->id_owner === $this->user()->id;
    }

    public function rules(): array
    {
        return [
            'auctionPlayerId' => 'required|integer',
            'newBasePrice' => 'nullable|integer|min:0',
        ];
    }
}
