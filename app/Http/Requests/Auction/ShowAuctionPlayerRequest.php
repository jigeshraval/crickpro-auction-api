<?php

namespace App\Http\Requests\Auction;

use Illuminate\Foundation\Http\FormRequest;

class ShowAuctionPlayerRequest extends FormRequest
{
    public function authorize(): bool
    {
        $auction = $this->route('auction');
        $auctionPlayer = $this->route('auctionPlayer');

        return $auction->id_owner === $this->user()->id && $auctionPlayer->id_auction === $auction->id;
    }

    public function rules(): array
    {
        return [];
    }
}
