<?php

namespace App\Http\Requests\Player;

use Illuminate\Foundation\Http\FormRequest;

class StorePlayerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|min:2|max:120',
            'displayName' => 'nullable|string|max:60',
            'role' => 'nullable|string|in:batter,bowler,all_rounder,wicket_keeper,wk_batter',
            'battingStyle' => 'nullable|string|in:right_hand,left_hand',
            'bowlingStyle' => 'nullable|string|max:40',
            'dateOfBirth' => 'nullable|date',
            'nationality' => 'nullable|string|max:60',
            'city' => 'nullable|string|max:80',
            'phone' => 'nullable|string|max:20',
            'jerseyNumber' => 'nullable|integer|min:0|max:999',
            'isCapped' => 'nullable|boolean',
            'notes' => 'nullable|string',
        ];
    }
}
