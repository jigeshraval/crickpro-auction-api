<?php

namespace App\Http\Requests\Auction;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTeamRequest extends FormRequest
{
    public function authorize(): bool
    {
        $auction = $this->route('auction');
        $team = $this->route('team');

        return $auction->id_owner === $this->user()->id && $team->id_auction === $auction->id;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|min:3|max:120',
            'shortName' => 'sometimes|string|min:2|max:12',
        ];
    }
}
