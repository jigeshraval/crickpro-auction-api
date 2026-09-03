<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['id_auction', 'id_overlay_template', 'is_active', 'display_mode', 'config'])]
class AuctionOverlay extends Model
{
    public const DISPLAY_FULL_FRAME = 'full_frame';

    public const DISPLAY_LOWER_THIRD = 'lower_third';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'config' => 'array',
        ];
    }

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class, 'id_auction');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(OverlayTemplate::class, 'id_overlay_template');
    }
}
