<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CrickproLink extends Model
{
    protected $fillable = [
        'id_owner',
        'crickpro_user_id',
        'token',
        'name',
    ];

    protected $hidden = ['token'];
}
