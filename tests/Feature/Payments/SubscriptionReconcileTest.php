<?php

namespace Tests\Feature\Payments;

use App\Models\Auction;
use App\Models\SubscriptionHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The reconcile backstop (subscription:reconcile) — the subscription analogue of
 * registration's QR poll. When the `payment_link.paid` webhook is missed, the
 * scheduled poll asks Razorpay for the link status and activates paid ones.
 */
class SubscriptionReconcileTest extends TestCase
{
    use PaymentTestHelpers;
    use RefreshDatabase;

    private string $owner;

    private int $auctionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPayments();
        config(['subscription.prices.INR.4_teams' => 679]);
        // Reconcile only touches rows older than this; keep it 0 so freshly
        // created test rows are eligible immediately.
        config(['payments.poll_min_age_seconds' => 0]);

        $this->owner = $this->tokenFor('organiser@example.com', 'IN');
        $this->auctionId = (int) $this->createAuction($this->owner)['id'];
    }

    /** Fake payment_links: POST (checkout) creates; GET (reconcile) echoes the ref with the given status. */
    private function fakeLinks(string $status): void
    {
        $this->fakeRazorpay([
            '*/payment_links*' => function (HttpRequest $r) use ($status) {
                if ($r->method() === 'POST') {
                    return Http::response(['id' => 'plink_TEST', 'short_url' => 'https://rzp.io/rzp/TEST', 'status' => 'created']);
                }
                parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
                $ref = $q['reference_id'] ?? '';

                return Http::response(['payment_links' => [[
                    'id' => 'plink_TEST', 'reference_id' => $ref, 'currency' => 'INR',
                    'amount' => 67_900, 'amount_paid' => 67_900, 'status' => $status,
                ]]]);
            },
        ]);
    }

    private function checkout(): string
    {
        return $this->as($this->owner)
            ->postJson('/v1/subscription/checkout', ['auctionId' => $this->auctionId, 'teams' => 4])
            ->assertOk()->json('referenceId');
    }

    public function test_reconcile_activates_a_pending_row_whose_link_is_paid(): void
    {
        $this->fakeLinks('paid');
        $ref = $this->checkout();

        $this->assertSame(1, (new \App\Services\SubscriptionService)->reconcilePending());

        $sub = SubscriptionHistory::where('transaction_id', $ref)->first();
        $this->assertTrue((bool) $sub->is_active);
        $this->assertSame(SubscriptionHistory::STATUS_ACTIVE, $sub->status);

        $auction = Auction::find($this->auctionId);
        $this->assertSame(4, (int) $auction->max_teams);
        $this->assertTrue((bool) $auction->overlay_access);
    }

    public function test_reconcile_leaves_an_unpaid_link_pending(): void
    {
        $this->fakeLinks('created');
        $ref = $this->checkout();

        $this->assertSame(0, (new \App\Services\SubscriptionService)->reconcilePending());
        $this->assertSame(SubscriptionHistory::STATUS_PENDING, SubscriptionHistory::where('transaction_id', $ref)->value('status'));
    }

    public function test_reconcile_is_idempotent_over_repeated_runs(): void
    {
        $this->fakeLinks('paid');
        $this->checkout();

        $this->assertSame(1, (new \App\Services\SubscriptionService)->reconcilePending());
        // Second run: row already active → not re-counted, no duplicate.
        $this->assertSame(0, (new \App\Services\SubscriptionService)->reconcilePending());
        $this->assertSame(1, SubscriptionHistory::where('source', 'razorpay')->where('is_active', true)->count());
    }

    public function test_command_runs(): void
    {
        $this->fakeLinks('paid');
        $this->checkout();

        $this->artisan('subscription:reconcile')->assertSuccessful();
        $this->assertSame(1, SubscriptionHistory::where('source', 'razorpay')->where('is_active', true)->count());
    }
}
