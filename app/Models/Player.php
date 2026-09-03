<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'id_owner', 'id_user', 'name', 'display_name', 'photo_url', 'role', 'batting_style',
    'bowling_style', 'date_of_birth', 'nationality', 'city', 'phone', 'jersey_number',
    'is_capped', 'stats', 'notes',
])]
class Player extends Model
{
    use SoftDeletes;

    public const ROLE_BATTER = 'batter';

    public const ROLE_BOWLER = 'bowler';

    public const ROLE_ALL_ROUNDER = 'all_rounder';

    public const ROLE_WICKET_KEEPER = 'wicket_keeper';

    public const ROLE_WK_BATTER = 'wk_batter';

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'is_capped' => 'boolean',
            'stats' => 'array',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_owner');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_user');
    }

    public function auctionPlayers(): HasMany
    {
        return $this->hasMany(AuctionPlayer::class, 'id_player');
    }
}
