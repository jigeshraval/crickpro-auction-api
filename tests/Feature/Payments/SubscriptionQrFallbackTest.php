<?php

namespace Tests\Feature\Payments;

use App\Models\SubscriptionHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * When UPI QR creation fails (Razorpay 500 / test-mode can't mint QRs), an INR
 * checkout must NOT dead-end — it falls back to a hosted Payment Link.
 */
class SubscriptionQrFallbackTest extends TestCase
{
    use PaymentTestHelpers;
    use RefreshDatabase;

    public function test_inr_falls_back_to_a_payment_link_when_qr_creation_fails(): void
    {
        $this->bootPayments();
        $this->fakeRazorpay([
            // QR creation 500s (as it does on test-mode keys).
            '*/payments/qr_codes' => Http::response(['error' => ['code' => 'SERVER_ERROR', 'description' => 'trouble']], 500),
            // The link fallback succeeds.
            '*/payment_links' => fn ($r) => Http::response([
                'id' => 'plink_FALLBACK', 'short_url' => 'https://rzp.io/i/fallback', 'status' => 'created',
                'reference_id' => $r['reference_id'], 'amount' => $r['amount'], 'currency' => $r['currency'],
            ]),
        ]);

        $owner = $this->tokenFor('in@example.com', 'IN');
        $auction = $this->createAuction($owner);

        $this->as($owner)->postJson('/v1/subscription/checkout', ['auctionId' => $auction['id'], 'teams' => 4])
            ->assertOk()
            ->assertJsonPath('gateway', 'razorpay')
            ->assertJsonPath('mode', 'link')
            ->assertJsonPath('shortUrl', 'https://rzp.io/i/fallback');

        // A single INR pending row via the link (the QR attempt's row was cancelled).
        $this->assertDatabaseHas('subscription_history', [
            'id_auction' => $auction['id'], 'currency' => 'INR', 'status' => 'pending', 'is_active' => false,
        ]);
    }
}
