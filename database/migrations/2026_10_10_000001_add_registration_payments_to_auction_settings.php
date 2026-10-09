<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Per-auction registration payment settings (spec §1). Fee in paise. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auction_settings', function (Blueprint $table) {
            $table->string('registration_mode', 16)->default('none'); // none|razorpay|manual
            $table->unsignedInteger('registration_fee')->default(0);
            $table->string('fee_bearer', 16)->default('organiser');   // organiser|player
            $table->text('manual_instructions')->nullable();
            $table->string('manual_qr_url')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('auction_settings', function (Blueprint $table) {
            $table->dropColumn(['registration_mode', 'registration_fee', 'fee_bearer', 'manual_instructions', 'manual_qr_url']);
        });
    }
};
