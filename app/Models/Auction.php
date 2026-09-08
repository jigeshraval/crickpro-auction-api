<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'id_owner', 'id_currency', 'id_crickpro_series', 'crickpro_series_name',
    'name', 'slug', 'season_label', 'description', 'logo_url', 'cover_url',
    'venue', 'access_code', 'overlay_secret', 'overlay_theme', 'max_teams', 'overlay_access', 'is_listed', 'status',
    'current_round', 'id_current_auction_player', 'event_sequence',
    'scheduled_at', 'started_at', 'paused_at', 'completed_at',
])]
class Auction extends Model
{
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_READY = 'ready';

    public const STATUS_LIVE = 'live';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_ABANDONED = 'abandoned';

    protected function casts(): array
    {
        return [
            'is_listed' => 'boolean',
            'overlay_theme' => 'array',
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'paused_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_owner');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'id_currency');
    }

    public function settings(): HasOne
    {
        return $this->hasOne(AuctionSetting::class, 'id_auction');
    }

    public function teams(): HasMany
    {
        return $this->hasMany(Team::class, 'id_auction');
    }

    public function categories(): HasMany
    {
        return $this->hasMany(AuctionCategory::class, 'id_auction');
    }

    public function auctionPlayers(): HasMany
    {
        return $this->hasMany(AuctionPlayer::class, 'id_auction');
    }

    public function overlays(): HasMany
    {
        return $this->hasMany(AuctionOverlay::class, 'id_auction');
    }

    public function activeOverlay(): HasOne
    {
        return $this->hasOne(AuctionOverlay::class, 'id_auction')->where('is_active', true);
    }

    public function broadcastSessions(): HasMany
    {
        return $this->hasMany(BroadcastSession::class, 'id_auction');
    }

    public function currentAuctionPlayer(): BelongsTo
    {
        return $this->belongsTo(AuctionPlayer::class, 'id_current_auction_player');
    }
}
