<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from crickpro-api's ApiGateway pattern (app/Services/ApiGateway.php),
 * trimmed to what an outbound-only WhatsApp OTP sender needs — no
 * unit_cost/google_bucket/fail_open, since those exist there only for its
 * YouTube-quota bucket logic, which doesn't apply here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_calls', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 50);
            $table->string('message')->nullable();
            $table->string('identifier')->nullable();
            $table->text('request')->nullable();
            $table->text('response')->nullable();
            $table->smallInteger('response_status')->nullable();
            $table->decimal('response_time', 10, 2)->nullable();
            $table->string('ip_address')->nullable();
            $table->foreignId('id_user')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['channel', 'created_at']);
            $table->index(['identifier', 'created_at']);
        });

        Schema::create('api_limits', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 50)->unique();
            $table->integer('hourly_limit')->nullable();
            $table->integer('daily_limit')->default(1000);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });

        DB::table('api_limits')->insert([
            'channel' => 'whatsapp',
            'hourly_limit' => 100,
            'daily_limit' => 500,
            'is_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('api_limits');
        Schema::dropIfExists('api_calls');
    }
};
