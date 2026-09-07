<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Player role type — Batter / Bowler / Wicket Keeper / All Rounder (seeded). */
#[Fillable(['name', 'short', 'sort_order'])]
class RoleType extends Model
{
    public const BATTER = 1;

    public const BOWLER = 2;

    public const WICKET_KEEPER = 3;

    public const ALL_ROUNDER = 4;
}
