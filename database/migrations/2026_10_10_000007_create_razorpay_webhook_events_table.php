<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Webhook idempotency + audit. (source, event_id) unique → duplicates are no-ops. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('razorpay_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('source', 16); // razorpay|razorpayx
            $table->string('event_id', 80);
            $table->string('type', 64);
            $table->longText('payload');
            $table->timestamp('processed_at')->nullable();
            $table->string('result')->nullable();
            $table->timestamps();

            $table->unique(['source', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('razorpay_webhook_events');
    }
};
