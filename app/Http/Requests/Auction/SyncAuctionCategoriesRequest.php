<?php

namespace App\Http\Requests\Auction;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Replaces the auction's whole category set from the Edit Auction screen — the
 * same shape quick-start accepts. Upsert-by-code on the controller side; a
 * removed category that still has players is rejected there (not here).
 */
class SyncAuctionCategoriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('auction')->id_owner === $this->user()->id;
    }

    public function rules(): array
    {
        return [
            'categories' => 'present|array|max:12',
            'categories.*.code' => 'required|string|min:1|max:24',
            'categories.*.name' => 'required|string|min:1|max:60',
            'categories.*.color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'categories.*.defaultBasePrice' => 'nullable|integer|min:0',
        ];
    }
}
