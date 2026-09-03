<?php

namespace App\Http\Requests\Auction;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAuctionPlayerRequest extends FormRequest
{
    public function authorize(): bool
    {
        $auction = $this->route('auction');
        $auctionPlayer = $this->route('auctionPlayer');

        return $auction->id_owner === $this->user()->id && $auctionPlayer->id_auction === $auction->id;
    }

    public function rules(): array
    {
        return [
            // Identity fields — patched onto the underlying library Player.
            'name' => 'sometimes|string|min:2|max:120',
            'phone' => 'sometimes|nullable|string|max:20',
            'role' => 'sometimes|string|in:batter,bowler,all_rounder,wicket_keeper,wk_batter',
            'battingStyle' => 'sometimes|nullable|string|in:right_hand,left_hand',
            'bowlingStyle' => 'sometimes|nullable|string|max:40',
            'dateOfBirth' => 'sometimes|nullable|date',
            'city' => 'sometimes|nullable|string|max:80',
            'jerseyNumber' => 'sometimes|nullable|integer|min:0|max:999',
            'isCapped' => 'sometimes|boolean',
            // Auction-scoped fields — patched onto this AuctionPlayer row.
            'categoryCode' => 'sometimes|nullable|string|max:24',
            'basePrice' => 'sometimes|integer|min:0',
            'isOverseas' => 'sometimes|boolean',
            'customFieldValues' => 'sometimes|nullable|array',
        ];
    }
}
