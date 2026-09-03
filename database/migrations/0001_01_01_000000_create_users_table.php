<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->string('mobile')->nullable()->unique();
            $table->string('masked_mobile')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            // Nullable: an admin-created "placeholder" account (verified=0, no
            // password) can be claimed via /register instead of dead-ending as
            // "already registered". See AuthController::register().
            $table->string('password')->nullable();
            $table->boolean('verified')->default(false);
            // 1 = active, 3 = blocked (see AuthController::assertNotBlocked()).
            $table->unsignedTinyInteger('status')->default(1);
            $table->string('block_reason')->nullable();
            $table->uuid('uid')->nullable()->unique();
            $table->string('profile_image')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
