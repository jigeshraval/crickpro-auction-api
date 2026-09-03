<?php

namespace App\Http\Requests\Auction;

use Illuminate\Foundation\Http\FormRequest;

class StoreAuctionPlayerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('auction')->id_owner === $this->user()->id;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|min:2|max:120',
            'phone' => 'nullable|string|max:20',
            'role' => 'nullable|string|in:batter,bowler,all_rounder,wicket_keeper,wk_batter',
            'battingStyle' => 'nullable|string|in:right_hand,left_hand',
            'bowlingStyle' => 'nullable|string|max:40',
            'dateOfBirth' => 'nullable|date',
            'city' => 'nullable|string|max:80',
            'jerseyNumber' => 'nullable|integer|min:0|max:999',
            'isCapped' => 'nullable|boolean',
            'categoryCode' => 'nullable|string|max:24',
            'basePrice' => 'nullable|integer|min:0',
            'isOverseas' => 'nullable|boolean',
            'customFieldValues' => 'nullable|array',
        ];
    }
}
