<?php

namespace Tests\Feature\Payments;

use App\Models\SubscriptionHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /v1/subscription/history — the signed-in user's own purchases, newest
 * first, paginated, camelCase, with the display fields the purchase-history
 * screens render (planLabel, amountDisplay, storeLabel, statusLabel).
 */
class SubscriptionHistoryTest extends TestCase
{
    use PaymentTestHelpers;
    use RefreshDatabase;

    private string $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPayments();
        $this->owner = $this->tokenFor('organiser@example.com', 'IN');
    }

    private function row(int $userId, array $attrs = []): SubscriptionHistory
    {
        return SubscriptionHistory::create(array_merge([
            'id_user' => $userId,
            'plan_id' => '8_teams',
            'max_teams' => 8,
            'amount' => 1699,
            'currency' => 'INR',
            'transaction_id' => 'sub_'.uniqid(),
            'source' => 'razorpay',
            'store' => 'web',
            'is_active' => true,
            'status' => SubscriptionHistory::STATUS_ACTIVE,
        ], $attrs));
    }

    public function test_lists_only_the_users_own_rows_newest_first(): void
    {
        $me = $this->userId('organiser@example.com');
        $this->tokenFor('stranger@example.com');
        $other = $this->userId('stranger@example.com');

        $this->row($me, ['plan_id' => '4_teams', 'max_teams' => 4, 'amount' => 679]);
        $this->row($me, ['plan_id' => '8_teams', 'max_teams' => 8, 'amount' => 1699]);
        $this->row($other, ['plan_id' => '12_teams']);

        $res = $this->as($this->owner)->getJson('/v1/subscription/history')->assertOk();

        $res->assertJsonPath('status', 'success')
            ->assertJsonPath('currentPage', 1)
            ->assertJsonPath('nextPage', null)
            ->assertJsonCount(2, 'subscriptions');

        // Newest first (the 8-team row was created last).
        $res->assertJsonPath('subscriptions.0.planId', '8_teams')
            ->assertJsonPath('subscriptions.0.planLabel', 'Up to 8 Teams')
            ->assertJsonPath('subscriptions.0.amountDisplay', 'INR 1,699.00')
            ->assertJsonPath('subscriptions.0.storeLabel', 'Web')
            ->assertJsonPath('subscriptions.0.statusLabel', 'Active');
    }

    public function test_excludes_deleted_rows(): void
    {
        $me = $this->userId('organiser@example.com');
        $this->row($me);
        $this->row($me, ['status' => SubscriptionHistory::STATUS_DELETED, 'is_active' => false]);

        $this->as($this->owner)->getJson('/v1/subscription/history')->assertOk()->assertJsonCount(1, 'subscriptions');
    }

    public function test_paginates(): void
    {
        $me = $this->userId('organiser@example.com');
        foreach (range(1, 3) as $i) {
            $this->row($me);
        }

        $res = $this->as($this->owner)->getJson('/v1/subscription/history?perPage=2')->assertOk();
        $res->assertJsonCount(2, 'subscriptions')->assertJsonPath('nextPage', 2);

        $this->as($this->owner)->getJson('/v1/subscription/history?perPage=2&page=2')->assertOk()
            ->assertJsonCount(1, 'subscriptions')->assertJsonPath('nextPage', null);
    }

    public function test_unpriced_revenuecat_row_has_null_amount_display(): void
    {
        $me = $this->userId('organiser@example.com');
        $this->row($me, ['source' => 'revenuecat', 'store' => 'app_store', 'amount' => null, 'currency' => null]);

        $this->as($this->owner)->getJson('/v1/subscription/history')->assertOk()
            ->assertJsonPath('subscriptions.0.amountDisplay', null)
            ->assertJsonPath('subscriptions.0.storeLabel', 'App Store');
    }

    public function test_requires_auth(): void
    {
        $this->guest()->getJson('/v1/subscription/history')->assertUnauthorized();
    }
}
