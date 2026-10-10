<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Match crickpro-api-v2's table names (`country`, `state`, `city`) — the geo
 * data is a copy of those tables with the same ids. Foreign keys pointing at
 * the old names (states/cities/users.id_country) follow the rename.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('countries', 'country');
        Schema::rename('states', 'state');
        Schema::rename('cities', 'city');
    }

    public function down(): void
    {
        Schema::rename('city', 'cities');
        Schema::rename('state', 'states');
        Schema::rename('country', 'countries');
    }
};
