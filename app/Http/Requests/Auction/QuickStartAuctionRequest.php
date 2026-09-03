<?php

namespace App\Http\Requests\Auction;

use Illuminate\Foundation\Http\FormRequest;

/** Mirrors the source Node app's quick-start Zod schema (auction.routes.ts). */
class QuickStartAuctionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|min:2|max:150',
            'currencyId' => 'nullable|integer|exists:currencies,id',
            'seasonLabel' => 'nullable|string|max:60',
            'purse' => 'nullable|integer|min:0',
            'squadMax' => 'nullable|integer|min:1|max:50',
            'venue' => 'nullable|string|max:200',
            'scheduledAt' => 'nullable|date',
            'minBasePrice' => 'nullable|integer|min:0',
            'bidIncrementMode' => 'nullable|string|in:flat,slab',
            'bidIncrementFlat' => 'nullable|integer|min:1',
            'bidIncrementSlabs' => 'nullable|array',
            'bidIncrementSlabs.*.upTo' => 'required_with:bidIncrementSlabs|integer|min:0',
            'bidIncrementSlabs.*.increment' => 'required_with:bidIncrementSlabs|integer|min:1',
            'overlayCode' => 'nullable|string|max:60',
            'teams' => 'nullable|array|max:24',
            'teams.*.name' => 'required|string|min:1|max:120',
            'teams.*.shortName' => 'required|string|min:1|max:12',
            'teams.*.primaryColor' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'teams.*.secondaryColor' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'categories' => 'nullable|array|max:12',
            'categories.*.code' => 'required|string|min:1|max:24',
            'categories.*.name' => 'required|string|min:1|max:60',
            'categories.*.color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'categories.*.defaultBasePrice' => 'nullable|integer|min:0',
        ];
    }
}
