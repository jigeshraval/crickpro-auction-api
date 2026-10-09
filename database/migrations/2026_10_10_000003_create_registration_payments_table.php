<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per payment ATTEMPT (each Razorpay QR is its own row; a manual
 * registration has one manual row). Money columns are paise. Financial rows
 * deliberately do NOT cascade-delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_registration')->constrained('player_registrations');
            $table->foreignId('id_auction')->constrained('auctions');
            $table->foreignId('id_owner')->constrained('users');
            $table->string('provider', 16);            // razorpay|manual
            $table->string('fee_bearer', 16)->nullable();
            $table->string('razorpay_qr_id', 40)->nullable()->unique();
            $table->string('qr_image_url', 500)->nullable();
            $table->timestamp('qr_expires_at')->nullable();
            $table->string('razorpay_payment_id', 40)->nullable()->unique();
            $table->unsignedInteger('base_amount');
            $table->unsignedInteger('platform_fee')->default(0);
            $table->unsignedInteger('gateway_fee')->default(0); // estimate until capture, then Razorpay `fee` (incl. GST)
            $table->unsignedInteger('gateway_tax')->default(0); // Razorpay `tax` (GST part of fee), informational
            $table->unsignedInteger('total_amount');
            $table->integer('organiser_net');
            $table->unsignedInteger('refunded_amount')->default(0);
            $table->integer('organiser_refunded')->default(0);
            $table->unsignedInteger('platform_fee_refunded')->default(0);
            $table->integer('gateway_fee_refunded')->default(0);
            $table->string('status', 24); // created|paid|partially_refunded|refunded|disputed|expired
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->string('proof_url')->nullable();
            $table->string('utr', 40)->nullable();
            $table->timestamps();

            $table->index(['id_auction', 'status']);
            $table->index(['id_owner', 'status']);
            $table->index(['status', 'qr_expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_payments');
    }
};
