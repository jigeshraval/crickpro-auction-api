<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'id_owner', 'id_crickpro_player', 'id_user', 'name', 'display_name', 'photo_url', 'role', 'id_role_type', 'batting_style',
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

    /** role enum → role_types id (wk_batter folds into Wicket Keeper). */
    public const ROLE_TO_TYPE = [
        'batter' => 1, 'bowler' => 2, 'wicket_keeper' => 3, 'wk_batter' => 3, 'all_rounder' => 4,
    ];

    protected static function booted(): void
    {
        // Keep id_role_type in step with the `role` string, unless a role type was
        // set explicitly. So every path (registration, manual add, bulk) normalises.
        static::saving(function (Player $player) {
            if ($player->isDirty('role') && ! $player->isDirty('id_role_type') && isset(self::ROLE_TO_TYPE[$player->role])) {
                $player->id_role_type = self::ROLE_TO_TYPE[$player->role];
            }
        });
    }

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

    public function roleType(): BelongsTo
    {
        return $this->belongsTo(RoleType::class, 'id_role_type');
    }
}
