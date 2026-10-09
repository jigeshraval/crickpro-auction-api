<?php

namespace Tests\Feature\Payments;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PaymentSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_tables_and_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumns('auction_settings', [
            'registration_mode', 'registration_fee', 'fee_bearer', 'manual_instructions', 'manual_qr_url',
        ]));
        $this->assertTrue(Schema::hasColumns('player_registrations', [
            'id_auction', 'id_invitation', 'payload', 'mode', 'status', 'id_auction_player', 'public_token', 'reject_reason', 'flag_reason',
        ]));
        $this->assertTrue(Schema::hasColumns('registration_payments', [
            'id_registration', 'id_auction', 'id_owner', 'provider', 'fee_bearer', 'razorpay_qr_id', 'qr_image_url', 'qr_expires_at',
            'razorpay_payment_id', 'base_amount', 'platform_fee', 'gateway_fee', 'gateway_tax', 'total_amount', 'organiser_net',
            'refunded_amount', 'organiser_refunded', 'platform_fee_refunded', 'gateway_fee_refunded', 'status', 'paid_at',
            'available_at', 'proof_url', 'utr',
        ]));
        $this->assertTrue(Schema::hasColumns('payment_refunds', ['id_payment', 'razorpay_refund_id', 'amount', 'organiser_debit', 'platform_portion', 'gateway_portion', 'status', 'initiated_by']));
        $this->assertTrue(Schema::hasColumns('payment_disputes', ['id_payment', 'razorpay_dispute_id', 'amount', 'organiser_hold', 'status']));
        $this->assertTrue(Schema::hasColumns('wallets', ['id_owner', 'razorpayx_contact_id', 'negative_alerted_at']));
        $this->assertTrue(Schema::hasColumns('wallet_ledger', ['id_owner', 'type', 'amount', 'available_at', 'ref_type', 'ref_id', 'idempotency_key', 'description', 'created_at']));
        $this->assertTrue(Schema::hasColumns('payout_accounts', ['id_owner', 'type', 'contact_id', 'fund_account_id', 'validation_id', 'masked_account', 'ifsc', 'holder_name', 'verified_name', 'status', 'failure_reason', 'is_default', 'deleted_at']));
        $this->assertTrue(Schema::hasColumns('payouts', ['id_owner', 'id_payout_account', 'amount', 'fee', 'net_amount', 'razorpayx_payout_id', 'status', 'failure_reason', 'utr']));
        $this->assertTrue(Schema::hasColumns('razorpay_webhook_events', ['source', 'event_id', 'type', 'payload', 'processed_at', 'result']));
    }

    public function test_registrations_cannot_be_removed_by_deleting_their_auction(): void
    {
        $fk = collect(Schema::getForeignKeys('player_registrations'))->first(fn ($fk) => $fk['columns'] === ['id_auction']);

        $this->assertNotNull($fk);
        $this->assertSame('restrict', strtolower((string) $fk['on_delete']));
    }
}
