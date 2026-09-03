<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('api_limits')->insertOrIgnore([
            'channel' => 'whatsapp-webhook',
            'hourly_limit' => null,
            'daily_limit' => 500,
            'is_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('api_limits')->where('channel', 'whatsapp-webhook')->delete();
    }
};
