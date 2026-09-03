<?php

namespace App\Http\Requests\Auction;

use Illuminate\Foundation\Http\FormRequest;

class ReorderAuctionPlayersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('auction')->id_owner === $this->user()->id;
    }

    public function rules(): array
    {
        return [
            'orderedIds' => 'required|array|min:1',
            'orderedIds.*' => 'integer',
        ];
    }
}
