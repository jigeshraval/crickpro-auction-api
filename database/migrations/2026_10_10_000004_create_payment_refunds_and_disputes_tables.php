<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Refund + dispute records. Not in the spec's table list, but needed to know
 * the exact organiser debit/hold to reverse on refund.failed / dispute.won.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_payment')->constrained('registration_payments');
            $table->string('razorpay_refund_id', 40)->unique();
            $table->unsignedInteger('amount');
            $table->integer('organiser_debit');
            $table->integer('platform_portion');
            $table->integer('gateway_portion');
            $table->string('status', 16);       // created|processed|failed
            $table->string('initiated_by', 16); // organiser|external
            $table->timestamps();
        });

        Schema::create('payment_disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_payment')->constrained('registration_payments');
            $table->string('razorpay_dispute_id', 40)->unique();
            $table->unsignedInteger('amount');
            $table->integer('organiser_hold');
            $table->string('status', 16); // open|won|lost|closed
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_disputes');
        Schema::dropIfExists('payment_refunds');
    }
};
