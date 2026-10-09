<?php

namespace App\Services\Payments;

use App\Models\Wallet;
use App\Models\WalletLedger;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Organiser wallet = append-only wallet_ledger (signed paise).
 *   balance   = Σ amount
 *   pending   = Σ credits with available_at > now
 *   available = balance − pending  (= Σ matured credits + Σ all debits)
 *
 * Every mutation runs inside withLock(): a transaction whose FIRST statement is
 * SELECT … FOR UPDATE on the owner's `wallets` row (MySQL row lock; sqlite
 * ignores FOR UPDATE and serialises writes anyway). Nested calls become
 * savepoints and keep the outer lock.
 *
 * Lock-order rule (MySQL deadlock avoidance):
 *   1. withLock() MUST be the outermost money transaction. Never call it from
 *      inside another DB::transaction() — under REPEATABLE READ an outer
 *      transaction may already hold a read snapshot taken BEFORE the wallet
 *      lock, and every plain read inside the callback (balances(), status
 *      checks) would then be stale.
 *   2. Order: wallet row (withLock) → registration_payments / payment_refunds /
 *      payment_disputes / payouts rows (lockForUpdate inside the callback) →
 *      player_registrations rows. Never lock a payment/registration row first
 *      and then enter withLock() — that is the reverse order and deadlocks
 *      against the webhook/poll paths.
 *
 * Freshness: because the wallet lock is taken before any consistent read and
 * every ledger writer for the owner holds that lock until commit, the
 * REPEATABLE READ snapshot established inside the callback already contains
 * every committed ledger row for the owner — balances() needs no locking read
 * (a LOCK IN SHARE MODE sum would take next-key gap locks on the id_owner
 * index and can deadlock ledger inserts of neighbouring owners).
 */
class WalletService
{
    public function __construct(private readonly OpsAlert $alerts) {}

    public function withLock(int $ownerId, Closure $callback): mixed
    {
        if (DB::transactionLevel() === 0) {
            // Create the row in its own autocommit statement, BEFORE the money
            // transaction: a duplicate-key INSERT IGNORE inside the transaction
            // would hold a shared lock and deadlock the FOR UPDATE below (1213),
            // and FOR UPDATE on a missing row takes a gap lock that deadlocks two
            // concurrent first-time inserts.
            $this->ensure($ownerId);
        }

        return DB::transaction(function () use ($ownerId, $callback) {
            $this->lockRow($ownerId);

            return $callback();
        });
    }

    /** Race-safe get-or-create of the owner's wallet row (no locks held afterwards outside a transaction). */
    public function ensure(int $ownerId): Wallet
    {
        $wallet = Wallet::where('id_owner', $ownerId)->first();
        if ($wallet) {
            return $wallet;
        }

        $this->insertRow($ownerId);

        return Wallet::where('id_owner', $ownerId)->firstOrFail();
    }

    /**
     * Exclusive lock FIRST; create only when missing (only reachable when
     * withLock runs nested in an already-open transaction, e.g. tests).
     */
    private function lockRow(int $ownerId): Wallet
    {
        $wallet = Wallet::where('id_owner', $ownerId)->lockForUpdate()->first();
        if ($wallet) {
            return $wallet;
        }

        $this->insertRow($ownerId);

        return Wallet::where('id_owner', $ownerId)->lockForUpdate()->firstOrFail();
    }

    private function insertRow(int $ownerId): void
    {
        DB::table('wallets')->insertOrIgnore([
            'id_owner' => $ownerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Append one ledger row. Returns null (no-op) when the idempotency key was
     * already booked. Call inside withLock().
     */
    public function post(
        int $ownerId,
        string $type,
        int $amount,
        CarbonInterface $availableAt,
        ?string $refType,
        ?int $refId,
        string $idempotencyKey,
        ?string $description = null,
    ): ?WalletLedger {
        // The unique idempotency_key index is the authority (a pre-check read
        // could be stale); a duplicate is a no-op.
        try {
            return WalletLedger::create([
                'id_owner' => $ownerId,
                'type' => $type,
                'amount' => $amount,
                'available_at' => $availableAt,
                'ref_type' => $refType,
                'ref_id' => $refId,
                'idempotency_key' => $idempotencyKey,
                'description' => $description,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /**
     * Decision-grade (withdraw/refund checks) only when called inside withLock()
     * for the same owner — see the freshness note on the class.
     *
     * @return array{balance:int, available:int, pending:int}
     */
    public function balances(int $ownerId): array
    {
        $row = WalletLedger::where('id_owner', $ownerId)
            ->selectRaw('COALESCE(SUM(amount), 0) AS balance')
            ->selectRaw('COALESCE(SUM(CASE WHEN amount > 0 AND available_at > ? THEN amount ELSE 0 END), 0) AS pending', [now()])
            ->first();

        $balance = (int) ($row->balance ?? 0);
        $pending = (int) ($row->pending ?? 0);

        return ['balance' => $balance, 'available' => $balance - $pending, 'pending' => $pending];
    }

    /**
     * Ops alert when the organiser is in real debt (balance < 0 — not covered
     * even by pending collections). At most once per 24h per owner; the flag
     * resets once the balance recovers. Withdrawals are blocked separately by
     * `available < amount`, which a negative available always fails.
     */
    public function alertIfNegative(int $ownerId): void
    {
        $balances = $this->balances($ownerId);
        $wallet = $this->ensure($ownerId);

        if ($balances['balance'] >= 0) {
            if ($wallet->negative_alerted_at !== null) {
                $wallet->update(['negative_alerted_at' => null]);
            }

            return;
        }

        if ($wallet->negative_alerted_at !== null && $wallet->negative_alerted_at->gt(now()->subDay())) {
            return;
        }

        $wallet->update(['negative_alerted_at' => now()]);
        $this->alerts->send('Organiser wallet balance is negative', ['ownerId' => $ownerId, ...$balances]);
    }
}
