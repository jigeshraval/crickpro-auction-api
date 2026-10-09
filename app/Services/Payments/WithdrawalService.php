<?php

namespace App\Services\Payments;

use App\Models\Payout;
use App\Models\PayoutAccount;
use App\Models\WalletLedger;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Withdraw (spec §7): debit the full amount inside the owner lock, then ask
 * RazorpayX to pay amount − fee. `reference_id` = X-Payout-Idempotency =
 * "payout_{id}" so webhooks can find the payout even if we never stored the
 * RazorpayX id.
 */
class WithdrawalService
{
    private const UNKNOWN_OUTCOME = 'Awaiting confirmation from RazorpayX.';

    public const RESEND_REJECTED = 'Re-send rejected by RazorpayX — awaiting ops review.';

    public const NEEDS_MANUAL = 'Needs manual reconciliation';

    private const RANK = [Payout::STATUS_QUEUED => 0, Payout::STATUS_PROCESSING => 1, Payout::STATUS_PROCESSED => 2];

    public function __construct(
        private readonly WalletService $wallet,
        private readonly FeeCalculator $fees,
        private readonly RazorpayClient $razorpay,
        private readonly OpsAlert $alerts,
    ) {}

    public static function reference(Payout $payout): string
    {
        return 'payout_'.$payout->id;
    }

    public function withdraw(int $ownerId, int $amount, int $payoutAccountId): Payout
    {
        $account = PayoutAccount::where('id_owner', $ownerId)->whereKey($payoutAccountId)->first();
        if (! $account) {
            throw new PaymentRuleException('Payout account not found.', 404);
        }
        if ($account->status !== PayoutAccount::STATUS_VERIFIED) {
            throw new PaymentRuleException('This payout account is not verified yet.');
        }

        $min = (int) config('payments.min_withdrawal');
        if ($amount < $min) {
            throw new PaymentRuleException('Minimum withdrawal is ₹'.intdiv($min, 100).'.');
        }

        $fee = $this->fees->payoutFee();
        if ($amount - $fee <= 0) {
            throw new PaymentRuleException('Amount does not cover the payout fee.');
        }

        $payout = $this->wallet->withLock($ownerId, function () use ($ownerId, $amount, $fee, $account) {
            $available = $this->wallet->balances($ownerId)['available'];
            if ($available < 0) {
                throw new PaymentRuleException('Withdrawals are paused because your wallet balance is negative.');
            }
            if ($available < $amount) {
                throw new PaymentRuleException('Amount exceeds your available balance.');
            }

            $payout = Payout::create([
                'id_owner' => $ownerId,
                'id_payout_account' => $account->id,
                'amount' => $amount,
                'fee' => $fee,
                'net_amount' => $amount - $fee,
                'status' => Payout::STATUS_QUEUED,
            ]);

            $this->wallet->post($ownerId, WalletLedger::TYPE_PAYOUT, -$amount, now(), 'payout', $payout->id,
                "payout:{$payout->id}", 'Withdrawal to '.$account->masked_account);

            return $payout;
        });

        try {
            $response = $this->sendPayout($payout, $account);
        } catch (RazorpayException $e) {
            if ($e->definitelyRejected()) { // 4xx OR never sent (gateway blocked / no credentials) — safe to restore
                $this->reverseRejected($payout, $e);

                throw new PaymentRuleException('Withdrawal failed — your balance has been restored. Please try again later.', 502);
            }

            $this->markUnknown($payout, $e);

            return $payout->refresh();
        }

        $this->applyStatus($payout, $response);

        return $payout->refresh();
    }

    /**
     * Recovery for payouts whose debit committed but whose RazorpayX call never
     * happened or whose outcome is unknown (review 2, I5):
     *   1. look the payout up by reference_id — if RazorpayX has it, apply its
     *      status and store its id (no resend);
     *   2. older than payments.razorpayx.recovery_max_hours → stop resending,
     *      mark "Needs manual reconciliation" + alert once;
     *   3. otherwise re-send with the SAME idempotency key / reference_id. A
     *      failure of the RESEND (even a 4xx) is not proof the first send didn't
     *      pay → stays queued + alert once, never reversed here.
     * Returns the number of payouts examined.
     */
    public function recoverStuck(int $olderThanMinutes = 10): int
    {
        $count = 0;
        $maxHours = (int) config('payments.razorpayx.recovery_max_hours', 24);

        Payout::where('status', Payout::STATUS_QUEUED)
            ->whereNull('razorpayx_payout_id')
            ->where('created_at', '<=', now()->subMinutes($olderThanMinutes))
            ->orderBy('id')
            ->each(function (Payout $stale) use (&$count, $maxHours) {
                $payout = Payout::find($stale->id); // may have been settled meanwhile
                if (! $payout || $payout->status !== Payout::STATUS_QUEUED || $payout->razorpayx_payout_id) {
                    return;
                }
                $count++;

                try {
                    $found = $this->razorpay->findPayoutByReference(self::reference($payout));
                } catch (RazorpayException $e) {
                    Log::warning('RazorpayX payout lookup failed — not re-sending this round', ['payoutId' => $payout->id, 'error' => $e->getMessage()]);

                    return;
                }

                if ($found !== null) {
                    $this->applyStatus($payout, $found);

                    return;
                }

                if ($payout->created_at->lte(now()->subHours($maxHours))) {
                    $this->flagOnce($payout, self::NEEDS_MANUAL, 'Payout needs manual reconciliation — auto-recovery stopped');

                    return;
                }

                $account = PayoutAccount::withTrashed()->find($payout->id_payout_account);
                if (! $account) {
                    return;
                }

                try {
                    $this->applyStatus($payout, $this->sendPayout($payout, $account));
                } catch (RazorpayException $e) {
                    Log::warning('RazorpayX payout re-send failed', ['payoutId' => $payout->id, 'error' => $e->getMessage()]);
                    $e->definitelyRejected()
                        ? $this->flagOnce($payout, self::RESEND_REJECTED, 'Payout re-send rejected — check RazorpayX before reversing')
                        : $this->markUnknown($payout, $e);
                }
            });

        return $count;
    }

    /** Sets failure_reason and alerts ops only when it wasn't already set to that reason. */
    private function flagOnce(Payout $payout, string $reason, string $subject): void
    {
        if ($payout->failure_reason === $reason) {
            return;
        }

        $payout->update(['failure_reason' => $reason]);
        $this->alerts->send($subject, ['payoutId' => $payout->id, 'ownerId' => $payout->id_owner]);
    }

    private function sendPayout(Payout $payout, PayoutAccount $account): array
    {
        return $this->razorpay->createPayout([
            'account_number' => config('payments.razorpayx.account_number'),
            'fund_account_id' => $account->fund_account_id,
            'amount' => $payout->net_amount,
            'currency' => 'INR',
            'mode' => $account->type === PayoutAccount::TYPE_VPA ? 'UPI' : 'IMPS',
            'purpose' => 'payout',
            'queue_if_low_balance' => true,
            'reference_id' => self::reference($payout),
            'narration' => 'CrickPro Auction',
        ], self::reference($payout));
    }

    private function reverseRejected(Payout $payout, RazorpayException $e): void
    {
        $this->reverse($payout, Payout::STATUS_FAILED, $e->notSent ? 'Payout could not be sent. Please try again later.' : 'Payout was rejected by the bank partner.');
    }

    /** Leaves the payout queued; the ops alert fires only the first time (failure_reason marks it). */
    private function markUnknown(Payout $payout, RazorpayException $e): void
    {
        Log::warning('RazorpayX payout outcome unknown', ['payoutId' => $payout->id, 'error' => $e->getMessage()]);

        if (in_array($payout->failure_reason, [self::UNKNOWN_OUTCOME, self::RESEND_REJECTED, self::NEEDS_MANUAL], true)) {
            return;
        }

        $payout->update(['failure_reason' => self::UNKNOWN_OUTCOME]);
        $this->alerts->send('Payout outcome unknown — awaiting webhook', ['payoutId' => $payout->id, 'ownerId' => $payout->id_owner, 'error' => $e->getMessage()]);
    }

    /** payout.* (RazorpayX) */
    public function onPayoutEvent(string $event, array $entity): string
    {
        $payout = isset($entity['id']) ? Payout::where('razorpayx_payout_id', $entity['id'])->first() : null;
        $reference = (string) ($entity['reference_id'] ?? '');
        if (! $payout && str_starts_with($reference, 'payout_')) {
            $payout = Payout::find((int) substr($reference, 7));
        }
        if (! $payout) {
            return 'ignored: unknown payout';
        }

        // The event name is authoritative for terminal outcomes; entity.status covers the rest.
        $forced = match ($event) {
            'payout.reversed' => Payout::STATUS_REVERSED,
            'payout.failed', 'payout.rejected' => Payout::STATUS_FAILED,
            'payout.processed' => Payout::STATUS_PROCESSED,
            default => null,
        };
        $this->applyStatus($payout, $entity, $forced);

        return 'payout '.$payout->fresh()->status;
    }

    /** Idempotent: a payout is credited back at most once (key payout_reversal:{id}). */
    public function reverse(Payout $payout, string $status, string $reason): void
    {
        $this->wallet->withLock($payout->id_owner, function () use ($payout, $status, $reason) {
            $payout = Payout::whereKey($payout->id)->lockForUpdate()->firstOrFail();
            if (in_array($payout->status, [Payout::STATUS_FAILED, Payout::STATUS_REVERSED], true)) {
                return;
            }

            $payout->update(['status' => $status, 'failure_reason' => Str::limit($reason, 250, '')]);
            $this->wallet->post($payout->id_owner, WalletLedger::TYPE_PAYOUT_REVERSAL, $payout->amount, now(), 'payout', $payout->id,
                "payout_reversal:{$payout->id}", 'Withdrawal returned to wallet');
        });
    }

    public static function mapStatus(?string $status): string
    {
        return match ($status) {
            'processing' => Payout::STATUS_PROCESSING,
            'processed' => Payout::STATUS_PROCESSED,
            'reversed' => Payout::STATUS_REVERSED,
            'failed', 'rejected', 'cancelled' => Payout::STATUS_FAILED,
            default => Payout::STATUS_QUEUED, // queued, pending, scheduled
        };
    }

    private function applyStatus(Payout $payout, array $entity, ?string $forced = null): void
    {
        $status = $forced ?? self::mapStatus($entity['status'] ?? null);
        $ids = array_filter([
            'razorpayx_payout_id' => $payout->razorpayx_payout_id ? null : ($entity['id'] ?? null),
            'utr' => $entity['utr'] ?? null,
        ]);

        if (in_array($status, [Payout::STATUS_FAILED, Payout::STATUS_REVERSED], true)) {
            if ($ids) {
                $payout->update($ids);
            }
            $this->reverse($payout, $status, (string) ($entity['status_details']['description'] ?? $entity['failure_reason'] ?? 'Payout '.$status.'.'));

            return;
        }

        // Non-terminal progress is written under the owner lock so it can never
        // overwrite a concurrent reversal's terminal status.
        $alert = false;
        $this->wallet->withLock($payout->id_owner, function () use ($payout, $status, &$ids, &$alert) {
            $payout = Payout::whereKey($payout->id)->lockForUpdate()->firstOrFail();
            $terminal = in_array($payout->status, [Payout::STATUS_FAILED, Payout::STATUS_REVERSED], true);
            // processed after we already reversed (credited back) = money may have left twice.
            $alert = $terminal && $status === Payout::STATUS_PROCESSED;
            $regress = (self::RANK[$status] ?? 0) < (self::RANK[$payout->status] ?? 0);

            if (! $terminal && ! $regress) {
                $ids['status'] = $status;
                if ($status === Payout::STATUS_PROCESSED) {
                    $ids['failure_reason'] = null;
                }
            }

            if ($ids) {
                $payout->update($ids);
            }
        });

        if ($alert) {
            $this->alerts->send('Payout processed after it was reversed — possible double payout', [
                'payoutId' => $payout->id, 'ownerId' => $payout->id_owner, 'razorpayxPayoutId' => $entity['id'] ?? null, 'utr' => $entity['utr'] ?? null,
            ]);
        }
    }
}
