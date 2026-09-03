<?php

namespace App\Repositories;

use App\Models\Auction;
use App\Models\AuctionSetting;

class AuctionSettingRepository
{
    public function update(Auction $auction, array $attributes): AuctionSetting
    {
        $settings = $auction->settings ?? $auction->settings()->create([]);
        $settings->update($attributes);

        return $settings;
    }
}
