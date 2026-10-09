<?php

namespace App\Services\Payments;

use App\Models\PaymentRefund;
use App\Models\PlayerRegistration;
use App\Models\RegistrationPayment;
use App\Models\WalletLedger;
use Illuminate\Support\Facades\Log;

/**
 * Refunds give the player back what they paid, fees included (spec §4).
 * The organiser is debited only their proportional share of the refund;
 * the platform/gateway portions are recorded on the payment for CrickPro's
 * reconciliation. Ledger debit is booked at refund creation (by us, or by
 * the refund.created webhook for Razorpay/bank-initiated refunds).
 *
 * Organiser refunds call Razorpay INSIDE the owner lock so a concurrent
 * withdrawal can't spend the money between the balance check and the debit.
 * A refund.created webhook racing with our own call blocks on the same lock,
 * then sees the payment_refunds row and no-ops.
 */
class RefundService
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly FeeCalculator $fees,
        private readonly RazorpayClient $razorpay,
    ) {}

    /**
     * Returns null when the gateway's answer is unknown (timeout / 5xx): the
     * refund may have been created, so the organiser is told it is being
     * processed and the refund.created webhook books it. The idempotency key
     * refund_{paymentId}_{attemptNo} makes a re-submitted attempt safe.
     */
    public function refundByOrganiser(RegistrationPayment $payment, ?int $amount): ?PaymentRefund
    {
        return $this->wallet->withLock($payment->id_owner, function () use ($payment, $amount) {
            $payment = RegistrationPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->provider !== RegistrationPayment::PROVIDER_RAZORPAY
                || ! in_array($payment->status, [RegistrationPayment::STATUS_PAID, RegistrationPayment::STATUS_PARTIALLY_REFUNDED], true)) {
                throw new PaymentRuleException('This payment cannot be refunded.');
            }

            // Amount-mismatch payments never credited the organiser (no collection
            // ledger row) — only CrickPro ops may refund them.
            if (! WalletLedger::where('idempotency_key', "collection:{$payment->id}")->exists()) {
                throw new PaymentRuleException('Under review by CrickPro.', 422);
            }

            $remaining = $payment->refundableAmount();
            $amount ??= $remaining;
            if ($amount < 100 || $amount > $remaining) {
                throw new PaymentRuleException('Refund amount must be between ₹1 and the refundable amount.');
            }

            $split = $this->split($payment, $amount);
            if ($this->wallet->balances($payment->id_owner)['balance'] < $split['organiser']) {
                throw new PaymentRuleException('Your wallet balance is too low to cover this refund.');
            }

            $attempt = PaymentRefund::where('id_payment', $payment->id)->count() + 1;

            try {
                $refund = $this->razorpay->refund(
                    $payment->razorpay_payment_id,
                    $amount,
                    ['registration_payment_id' => (string) $payment->id],
                    "refund_{$payment->id}_{$attempt}",
                );
            } catch (RazorpayException $e) {
                Log::warning('Razorpay refund failed', ['registrationPaymentId' => $payment->id, 'error' => $e->getMessage()]);

                if (! $e->definitelyRejected()) {
                    return null; // outcome unknown — refund.created will book it if it happened
                }

                throw new PaymentRuleException('The payment gateway could not process this refund. Please try again later.', 502);
            }

            return $this->record(
                $payment,
                (string) $refund['id'],
                $amount,
                $split,
                PaymentRefund::BY_ORGANISER,
                ($refund['status'] ?? null) === 'processed' ? PaymentRefund::STATUS_PROCESSED : PaymentRefund::STATUS_CREATED,
            );
        });
    }

    public function onRefundCreated(array $entity, string $status = PaymentRefund::STATUS_CREATED): string
    {
        $payment = $this->paymentFor($entity);
        if (! $payment || empty($entity['id'])) {
            return 'ignored: unknown payment';
        }

        return $this->wallet->withLock($payment->id_owner, function () use ($payment, $entity, $status) {
            $existing = PaymentRefund::where('razorpay_refund_id', $entity['id'])->lockForUpdate()->first();
            if ($existing) {
                // refund.processed racing refund.created: upgrade, never downgrade; a failed refund stays failed.
                if ($status === PaymentRefund::STATUS_PROCESSED && $existing->status === PaymentRefund::STATUS_CREATED) {
                    $existing->update(['status' => PaymentRefund::STATUS_PROCESSED]);

                    return 'refund processed';
                }

                return $existing->status === PaymentRefund::STATUS_FAILED ? 'noop: refund already failed' : 'noop: refund already booked';
            }

            $payment = RegistrationPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $amount = min((int) ($entity['amount'] ?? 0), $payment->total_amount - $payment->refunded_amount);
            if ($amount <= 0) {
                return 'ignored: nothing refundable';
            }

            $this->record($payment, (string) $entity['id'], $amount, $this->split($payment, $amount), PaymentRefund::BY_EXTERNAL, $status);

            return 'refund booked';
        });
    }

    /** Status only — but books the refund first if refund.created never arrived. */
    public function onRefundProcessed(array $entity): string
    {
        $refund = PaymentRefund::where('razorpay_refund_id', $entity['id'] ?? '')->first();
        if (! $refund) {
            return $this->onRefundCreated($entity, PaymentRefund::STATUS_PROCESSED);
        }

        return $this->wallet->withLock($refund->payment->id_owner, function () use ($refund) {
            $refund = PaymentRefund::whereKey($refund->id)->lockForUpdate()->firstOrFail();
            if ($refund->status === PaymentRefund::STATUS_FAILED) {
                return 'noop: refund already failed';
            }

            if ($refund->status !== PaymentRefund::STATUS_PROCESSED) {
                $refund->update(['status' => PaymentRefund::STATUS_PROCESSED]);
            }

            return 'refund processed';
        });
    }

    public function onRefundFailed(array $entity): string
    {
        $payment = $this->paymentFor($entity);
        if (! $payment || empty($entity['id'])) {
            return 'ignored: unknown payment';
        }

        return $this->wallet->withLock($payment->id_owner, function () use ($payment, $entity) {
            $refund = PaymentRefund::where('razorpay_refund_id', $entity['id'])->lockForUpdate()->first();
            if (! $refund) {
                // refund.failed arrived before refund.created: remember it as failed
                // (no ledger effect) so the late refund.created is a no-op.
                PaymentRefund::create([
                    'id_payment' => $payment->id,
                    'razorpay_refund_id' => (string) $entity['id'],
                    'amount' => max(0, (int) ($entity['amount'] ?? 0)),
                    'organiser_debit' => 0,
                    'platform_portion' => 0,
                    'gateway_portion' => 0,
                    'status' => PaymentRefund::STATUS_FAILED,
                    'initiated_by' => PaymentRefund::BY_EXTERNAL,
                ]);

                return 'refund failed before it was booked';
            }
            if ($refund->status === PaymentRefund::STATUS_FAILED) {
                return 'noop: already reversed';
            }

            $refund->update(['status' => PaymentRefund::STATUS_FAILED]);

            $payment = RegistrationPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $payment->refunded_amount -= $refund->amount;
            $payment->organiser_refunded -= $refund->organiser_debit;
            $payment->platform_fee_refunded -= $refund->platform_portion;
            $payment->gateway_fee_refunded -= $refund->gateway_portion;
            if (! $payment->isDisputeStatus()) {
                $payment->status = $payment->settledStatus();
            }
            $payment->save();

            $payment->registration()->where('status', PlayerRegistration::STATUS_REFUNDED)
                ->update(['status' => PlayerRegistration::STATUS_COMPLETED]);

            $this->wallet->post(
                $payment->id_owner,
                WalletLedger::TYPE_REFUND_REVERSAL,
                $refund->organiser_debit,
                now(),
                'payment_refund',
                $refund->id,
                "refund_reversal:{$refund->razorpay_refund_id}",
                'Refund failed — amount returned',
            );

            return 'refund reversed';
        });
    }

    /** @return array{organiser:int, platform:int, gateway:int} */
    public function split(RegistrationPayment $payment, int $amount): array
    {
        $cumulative = $payment->refunded_amount + $amount;
        $organiser = $this->fees->share($cumulative, $payment->total_amount, $payment->organiser_net) - $payment->organiser_refunded;
        $platform = $this->fees->share($cumulative, $payment->total_amount, $payment->platform_fee) - $payment->platform_fee_refunded;

        return ['organiser' => $organiser, 'platform' => $platform, 'gateway' => $amount - $organiser - $platform];
    }

    private function paymentFor(array $entity): ?RegistrationPayment
    {
        $id = $entity['payment_id'] ?? null;

        return $id ? RegistrationPayment::where('razorpay_payment_id', $id)->first() : null;
    }

    private function record(RegistrationPayment $payment, string $refundId, int $amount, array $split, string $by, string $status): PaymentRefund
    {
        $refund = PaymentRefund::create([
            'id_payment' => $payment->id,
            'razorpay_refund_id' => $refundId,
            'amount' => $amount,
            'organiser_debit' => $split['organiser'],
            'platform_portion' => $split['platform'],
            'gateway_portion' => $split['gateway'],
            'status' => $status,
            'initiated_by' => $by,
        ]);

        $payment->refunded_amount += $amount;
        $payment->organiser_refunded += $split['organiser'];
        $payment->platform_fee_refunded += $split['platform'];
        $payment->gateway_fee_refunded += $split['gateway'];
        if (! $payment->isDisputeStatus()) {
            $payment->status = $payment->settledStatus();
        }
        $payment->save();

        if ($payment->refunded_amount >= $payment->total_amount) {
            $payment->registration()->update(['status' => PlayerRegistration::STATUS_REFUNDED]);
        }

        $this->wallet->post(
            $payment->id_owner,
            WalletLedger::TYPE_REFUND,
            -$split['organiser'],
            now(),
            'payment_refund',
            $refund->id,
            "refund:{$refundId}",
            'Refund — '.($payment->registration?->payload['name'] ?? 'player'),
        );
        $this->wallet->alertIfNegative($payment->id_owner);

        return $refund;
    }
}
