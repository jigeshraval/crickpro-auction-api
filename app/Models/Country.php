<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A local copy of crickpro-api's `country` table, trimmed to id+name (+iso2/phonecode) — see the geo_location_tables migration for why. */
class Country extends Model
{
    protected $table = 'country';

    public $timestamps = false;

    protected $fillable = ['id', 'name', 'iso2', 'phonecode'];
}
