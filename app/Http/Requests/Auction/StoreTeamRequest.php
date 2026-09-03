<?php

namespace App\Http\Requests\Auction;

use Illuminate\Foundation\Http\FormRequest;

class StoreTeamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('auction')->id_owner === $this->user()->id;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|min:3|max:120',
            'shortName' => 'required|string|min:2|max:12',
        ];
    }
}
