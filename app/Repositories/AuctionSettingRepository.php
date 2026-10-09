<?php

namespace App\Repositories;

use App\Models\Auction;
use App\Models\AuctionSetting;

class AuctionSettingRepository
{
    /** The auction's settings row, created (with DB defaults loaded) if missing. */
    public function forAuction(Auction $auction): AuctionSetting
    {
        if ($auction->settings) {
            return $auction->settings;
        }

        $settings = $auction->settings()->create([])->refresh();
        $auction->setRelation('settings', $settings);

        return $settings;
    }

    public function update(Auction $auction, array $attributes): AuctionSetting
    {
        $settings = $this->forAuction($auction);
        $settings->update($attributes);

        return $settings;
    }
}
