<?php

namespace Tests\Feature\Payments;

use App\Mail\OpsAlertMail;
use App\Models\User;
use App\Models\WalletLedger;
use App\Services\Payments\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class WalletServiceTest extends TestCase
{
    use RefreshDatabase;

    private WalletService $wallet;

    private int $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['payments.ops_email' => 'ops@example.com']);
        $this->wallet = app(WalletService::class);
        $this->owner = User::factory()->create()->id;
    }

    public function test_balance_available_and_pending_maths(): void
    {
        $this->wallet->post($this->owner, WalletLedger::TYPE_COLLECTION, 47_820, now()->addDays(3), null, null, 'k1');
        $this->wallet->post($this->owner, WalletLedger::TYPE_COLLECTION, 10_000, now()->subMinute(), null, null, 'k2');
        $this->wallet->post($this->owner, WalletLedger::TYPE_REFUND, -5_000, now(), null, null, 'k3');

        $this->assertSame(['balance' => 52_820, 'available' => 5_000, 'pending' => 47_820], $this->wallet->balances($this->owner));
    }

    public function test_pending_credit_becomes_available_after_the_hold(): void
    {
        $this->wallet->post($this->owner, WalletLedger::TYPE_COLLECTION, 47_820, now()->addDays(3), null, null, 'k1');
        $this->travel(3)->days();
        $this->travel(1)->minutes();

        $this->assertSame(['balance' => 47_820, 'available' => 47_820, 'pending' => 0], $this->wallet->balances($this->owner));
    }

    public function test_post_is_idempotent_on_the_key(): void
    {
        $first = $this->wallet->post($this->owner, WalletLedger::TYPE_COLLECTION, 100, now(), null, null, 'same');
        $second = $this->wallet->post($this->owner, WalletLedger::TYPE_COLLECTION, 100, now(), null, null, 'same');

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertSame(1, WalletLedger::count());
    }

    public function test_with_lock_creates_the_wallet_row_and_returns_the_callback_value(): void
    {
        $this->assertSame('done', $this->wallet->withLock($this->owner, fn () => 'done'));
        $this->assertDatabaseHas('wallets', ['id_owner' => $this->owner]);
        $this->assertSame('again', $this->wallet->withLock($this->owner, fn () => 'again'));
        $this->assertDatabaseCount('wallets', 1);
    }

    public function test_post_returns_null_when_the_key_was_booked_by_someone_else(): void
    {
        // Simulates a concurrent writer whose row a stale pre-check would miss:
        // the unique index is the authority and the duplicate is a no-op.
        WalletLedger::create([
            'id_owner' => $this->owner, 'type' => WalletLedger::TYPE_COLLECTION, 'amount' => 100,
            'available_at' => now(), 'idempotency_key' => 'raced',
        ]);

        $this->assertNull($this->wallet->post($this->owner, WalletLedger::TYPE_COLLECTION, 100, now(), null, null, 'raced'));
        $this->assertSame(1, WalletLedger::count());
    }

    public function test_with_lock_on_an_existing_wallet_never_inserts(): void
    {
        $this->wallet->ensure($this->owner);
        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = strtolower($query->sql);
        });

        $this->wallet->withLock($this->owner, fn () => null);

        // Lock first; the INSERT IGNORE that took a shared lock (MySQL 1213) is gone.
        $this->assertSame([], array_values(array_filter($statements, fn ($sql) => str_contains($sql, 'insert'))));
        $this->assertStringStartsWith('select', $statements[0]);
        $this->assertStringContainsString('"wallets"', $statements[0]);
    }

    public function test_with_lock_creates_the_row_when_nested_in_an_open_transaction(): void
    {
        $result = DB::transaction(fn () => $this->wallet->withLock($this->owner, fn () => $this->wallet->withLock($this->owner, fn () => 'nested')));

        $this->assertSame('nested', $result);
        $this->assertDatabaseCount('wallets', 1);
    }

    public function test_ensure_is_idempotent(): void
    {
        $first = $this->wallet->ensure($this->owner);
        $second = $this->wallet->ensure($this->owner);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('wallets', 1);
    }

    public function test_negative_balance_alerts_ops_once_per_day(): void
    {
        Mail::fake();
        $this->wallet->post($this->owner, WalletLedger::TYPE_COLLECTION, 1_000, now()->subDay(), null, null, 'k1');
        $this->wallet->post($this->owner, WalletLedger::TYPE_REFUND, -3_000, now(), null, null, 'k2');

        $this->wallet->alertIfNegative($this->owner);
        $this->wallet->alertIfNegative($this->owner);

        Mail::assertSent(OpsAlertMail::class, 1);
        $this->assertDatabaseMissing('wallets', ['id_owner' => $this->owner, 'negative_alerted_at' => null]);
    }

    public function test_pending_credit_covering_a_debit_does_not_alert(): void
    {
        Mail::fake();
        $this->wallet->post($this->owner, WalletLedger::TYPE_COLLECTION, 50_066, now()->addDays(3), null, null, 'k1');
        $this->wallet->post($this->owner, WalletLedger::TYPE_REFUND, -50_066, now(), null, null, 'k2');

        $this->wallet->alertIfNegative($this->owner);

        Mail::assertNothingSent();
    }
}
