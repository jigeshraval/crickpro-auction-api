<?php

namespace Tests\Feature\Payments;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletEndpointsTest extends TestCase
{
    use PaymentTestHelpers;
    use RefreshDatabase;

    private string $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPayments();
        $this->fakeRazorpay();
        $this->owner = $this->tokenFor('organiser@example.com');
    }

    public function test_empty_wallet(): void
    {
        $this->as($this->owner)->getJson('/v1/wallet')
            ->assertOk()
            ->assertJsonPath('wallet.balance', 0)
            ->assertJsonPath('wallet.available', 0)
            ->assertJsonPath('wallet.pending', 0)
            ->assertJsonPath('wallet.canWithdraw', false)
            ->assertJsonPath('wallet.minWithdrawal', 10_000)
            ->assertJsonPath('wallet.payoutFee', 590)
            ->assertJsonPath('wallet.hasVerifiedPayoutAccount', false);
    }

    public function test_collection_is_pending_for_three_days_then_available(): void
    {
        $this->paidRegistration($this->owner);

        $this->as($this->owner)->getJson('/v1/wallet')
            ->assertJsonPath('wallet.balance', 50_000)
            ->assertJsonPath('wallet.pending', 50_000)
            ->assertJsonPath('wallet.available', 0)
            ->assertJsonPath('wallet.totals.collected', 50_000)
            ->assertJsonPath('wallet.canWithdraw', false);

        $this->travel(3)->days();
        $this->travel(1)->minutes();

        $this->as($this->owner)->getJson('/v1/wallet')
            ->assertJsonPath('wallet.available', 50_000)
            ->assertJsonPath('wallet.pending', 0)
            ->assertJsonPath('wallet.canWithdraw', true);
    }

    public function test_ledger_lists_newest_first_with_simple_pagination(): void
    {
        $this->paidRegistration($this->owner);

        $this->as($this->owner)->getJson('/v1/wallet/ledger')
            ->assertOk()
            ->assertJsonPath('currentPage', 1)
            ->assertJsonPath('nextPage', null)
            ->assertJsonPath('items.0.type', 'collection')
            ->assertJsonPath('items.0.amount', 50_000)
            ->assertJsonPath('items.0.isPending', true)
            ->assertJsonPath('items.0.description', 'Registration fee — Rahul Player');
    }

    public function test_wallets_are_per_owner(): void
    {
        $this->paidRegistration($this->owner);
        $other = $this->tokenFor('other@example.com');

        $this->as($other)->getJson('/v1/wallet')->assertJsonPath('wallet.balance', 0);
        $this->as($other)->getJson('/v1/wallet/ledger')->assertJsonCount(0, 'items');
    }

    public function test_wallet_requires_auth(): void
    {
        $this->guest()->getJson('/v1/wallet')->assertUnauthorized();
    }
}
