<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A local copy of crickpro-api's `state` table, trimmed to id+name+country — see the geo_location_tables migration for why. */
class State extends Model
{
    public $timestamps = false;

    protected $fillable = ['id', 'name', 'id_country'];

    public function country()
    {
        return $this->belongsTo(Country::class, 'id_country');
    }
}
