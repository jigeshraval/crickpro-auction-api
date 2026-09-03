<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A local copy of crickpro-api's `city` table, trimmed to id+name+state+country — see the geo_location_tables migration for why. */
class City extends Model
{
    public $timestamps = false;

    protected $fillable = ['id', 'name', 'id_state', 'id_country'];

    public function state()
    {
        return $this->belongsTo(State::class, 'id_state');
    }

    public function country()
    {
        return $this->belongsTo(Country::class, 'id_country');
    }
}
