<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subscription ledger for the auction app — adapted from api-v2's
 * subscription_history, scoped PER AUCTION (a team pack is bought for one
 * auction). `max_teams` is the team allowance the pack grants. The active
 * record's entitlement is denormalised onto `auctions` (max_teams, overlay_access)
 * for cheap gate checks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_user')->constrained('users')->cascadeOnDelete();
            $table->foreignId('id_auction')->nullable()->constrained('auctions')->nullOnDelete();
            $table->string('plan_id', 100);                 // store product id, e.g. "30_teams"
            $table->unsignedInteger('max_teams')->default(0);
            $table->decimal('amount', 10, 2)->nullable();
            $table->string('currency', 10)->nullable();
            $table->string('transaction_id', 255)->nullable();
            $table->enum('source', ['revenuecat', 'manual', 'promo'])->default('revenuecat');
            $table->string('store', 50)->nullable();         // play_store | app_store | admin
            $table->timestamp('starts_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->enum('status', ['pending', 'active', 'expired', 'refunded', 'cancelled', 'deleted'])->default('pending');
            $table->foreignId('created_by')->nullable();     // admin/ops user id for manual grants
            $table->string('notes', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['id_user', 'is_active']);
            $table->index(['id_auction', 'is_active']);
            $table->index('transaction_id');
        });

        Schema::table('auctions', function (Blueprint $table) {
            // Denormalised entitlement synced from the active subscription.
            $table->unsignedInteger('max_teams')->nullable()->after('overlay_secret');
            $table->boolean('overlay_access')->default(false)->after('max_teams');
        });
    }

    public function down(): void
    {
        Schema::table('auctions', function (Blueprint $table) {
            $table->dropColumn(['max_teams', 'overlay_access']);
        });
        Schema::dropIfExists('subscription_history');
    }
};
