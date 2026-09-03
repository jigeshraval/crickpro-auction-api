<?php

namespace App\Http\Requests\Player;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePlayerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('player')->id_owner === $this->user()->id;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|min:2|max:120',
            'displayName' => 'sometimes|nullable|string|max:60',
            'role' => 'sometimes|string|in:batter,bowler,all_rounder,wicket_keeper,wk_batter',
            'battingStyle' => 'sometimes|nullable|string|in:right_hand,left_hand',
            'bowlingStyle' => 'sometimes|nullable|string|max:40',
            'dateOfBirth' => 'sometimes|nullable|date',
            'nationality' => 'sometimes|nullable|string|max:60',
            'city' => 'sometimes|nullable|string|max:80',
            'phone' => 'sometimes|nullable|string|max:20',
            'jerseyNumber' => 'sometimes|nullable|integer|min:0|max:999',
            'isCapped' => 'sometimes|boolean',
            'notes' => 'sometimes|nullable|string',
        ];
    }
}
