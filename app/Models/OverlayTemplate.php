<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'description', 'preview_image_url', 'supports_lower_third', 'is_active', 'sort_order'])]
class OverlayTemplate extends Model
{
    protected function casts(): array
    {
        return [
            'supports_lower_third' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function overlays(): HasMany
    {
        return $this->hasMany(AuctionOverlay::class, 'id_overlay_template');
    }
}
