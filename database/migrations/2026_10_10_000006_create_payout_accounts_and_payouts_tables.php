<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** RazorpayX payout destinations (masked only — never raw numbers) + payouts. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_owner')->constrained('users');
            $table->string('type', 8); // bank|vpa
            $table->string('contact_id', 40);
            $table->string('fund_account_id', 40)->nullable()->index(); // not unique: Razorpay returns the same id for re-added details
            $table->string('validation_id', 40)->nullable()->unique();
            $table->string('masked_account', 64);
            $table->string('ifsc', 11)->nullable();
            $table->string('holder_name', 120)->nullable();
            $table->string('verified_name', 120)->nullable();
            $table->string('status', 16); // validating|verified|failed
            $table->string('failure_reason')->nullable();
            $table->boolean('is_default')->default(false);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['id_owner', 'status']);
        });

        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_owner')->constrained('users');
            $table->foreignId('id_payout_account')->constrained('payout_accounts');
            $table->unsignedInteger('amount');     // debited from the wallet
            $table->unsignedInteger('fee');        // payout fee incl. GST
            $table->unsignedInteger('net_amount'); // sent to the bank/UPI
            $table->string('razorpayx_payout_id', 40)->nullable()->unique();
            $table->string('status', 16); // queued|processing|processed|failed|reversed
            $table->string('failure_reason')->nullable();
            $table->string('utr', 64)->nullable();
            $table->timestamps();

            $table->index(['id_owner', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payouts');
        Schema::dropIfExists('payout_accounts');
    }
};
