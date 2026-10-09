<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** One row per organiser — the lock target for every wallet mutation. */
#[Fillable(['id_owner', 'razorpayx_contact_id', 'negative_alerted_at'])]
class Wallet extends Model
{
    protected function casts(): array
    {
        return ['id_owner' => 'integer', 'negative_alerted_at' => 'datetime'];
    }
}
