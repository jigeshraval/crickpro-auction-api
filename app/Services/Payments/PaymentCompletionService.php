<?php

namespace App\Services\Payments;

use App\Models\PlayerRegistration;
use App\Models\RegistrationPayment;
use App\Models\WalletLedger;
use Illuminate\Support\Facades\Log;

/**
 * The ONE "payment captured" path — qr_code.credited webhook, the status
 * endpoint's poll and the scheduled poll all call handleCapturedPayment().
 * Idempotent: keyed by razorpay_payment_id (unique) + ledger key
 * "collection:{payment id}", all under the owner's wallet lock.
 *
 * Must be called OUTSIDE any open transaction (withLock is the outermost money
 * transaction) — webhook/poll callers must not wrap it in DB::transaction().
 */
class PaymentCompletionService
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly RegistrationService $registrations,
        private readonly OpsAlert $alerts,
    ) {}

    public function handleCapturedPayment(array $entity, ?string $qrId): ?RegistrationPayment
    {
        $razorpayPaymentId = $entity['id'] ?? null;
        if (! $razorpayPaymentId || ($entity['status'] ?? null) !== 'captured') {
            return null;
        }

        $payment = RegistrationPayment::where('razorpay_payment_id', $razorpayPaymentId)->first()
            ?? ($qrId ? RegistrationPayment::where('razorpay_qr_id', $qrId)->first() : null);

        if (! $payment) {
            Log::warning('Razorpay payment for an unknown QR', ['paymentId' => $razorpayPaymentId, 'qrId' => $qrId]);

            return null;
        }

        $completed = false;

        $result = $this->wallet->withLock($payment->id_owner, function () use ($payment, $entity, $razorpayPaymentId, &$completed) {
            $payment = RegistrationPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->razorpay_payment_id === $razorpayPaymentId) {
                return $payment; // already completed — duplicate webhook / poll
            }

            if ($payment->razorpay_payment_id !== null) {
                $this->alerts->send('Second payment on a single-use QR', [
                    'registrationPaymentId' => $payment->id, 'existing' => $payment->razorpay_payment_id, 'new' => $razorpayPaymentId,
                ]);

                return $payment;
            }

            // Lock order: wallet (withLock) → payment → registration (WalletService docblock).
            $registration = PlayerRegistration::whereKey($payment->id_registration)->lockForUpdate()->firstOrFail();
            $amount = (int) ($entity['amount'] ?? 0);
            $fee = isset($entity['fee']) ? (int) $entity['fee'] : $payment->gateway_fee; // Razorpay `fee` already includes GST
            $tax = (int) ($entity['tax'] ?? 0);
            $paidAt = now();

            if ($amount !== $payment->total_amount) {
                // No collection is posted → organiser_net 0, so any later refund /
                // dispute on this payment debits nothing from the organiser, and
                // organiser refunds are blocked ("Under review by CrickPro").
                $payment->update([
                    'status' => RegistrationPayment::STATUS_PAID,
                    'razorpay_payment_id' => $razorpayPaymentId,
                    'gateway_fee' => $fee,
                    'gateway_tax' => $tax,
                    'organiser_net' => 0,
                    'paid_at' => $paidAt,
                ]);
                if (! in_array($registration->status, [PlayerRegistration::STATUS_COMPLETED, PlayerRegistration::STATUS_REFUNDED], true)) {
                    $registration->update([
                        'status' => PlayerRegistration::STATUS_NEEDS_REVIEW,
                        'flag_reason' => "Amount mismatch: expected {$payment->total_amount}, received {$amount}.",
                    ]);
                }
                $this->alerts->send('Registration payment amount mismatch', [
                    'registrationPaymentId' => $payment->id, 'expected' => $payment->total_amount, 'received' => $amount, 'razorpayPaymentId' => $razorpayPaymentId,
                ]);

                return $payment->refresh();
            }

            $net = $amount - $payment->platform_fee - $fee; // actuals win
            if ($net < 0) {
                // Actual gateway fee exceeded what the platform fee left over —
                // the organiser is never charged for it (and share() never sees a negative portion).
                $this->alerts->send('Gateway fee exceeds the organiser share — net clamped to 0', [
                    'registrationPaymentId' => $payment->id, 'amount' => $amount, 'platformFee' => $payment->platform_fee, 'gatewayFee' => $fee, 'computedNet' => $net,
                ]);
                $net = 0;
            }
            $availableAt = $paidAt->copy()->addDays((int) config('payments.hold_days'));

            $payment->update([
                'status' => RegistrationPayment::STATUS_PAID,
                'razorpay_payment_id' => $razorpayPaymentId,
                'gateway_fee' => $fee,
                'gateway_tax' => $tax,
                'organiser_net' => $net,
                'paid_at' => $paidAt,
                'available_at' => $availableAt,
            ]);

            $this->wallet->post(
                $payment->id_owner,
                WalletLedger::TYPE_COLLECTION,
                $net,
                $availableAt,
                'registration_payment',
                $payment->id,
                "collection:{$payment->id}",
                'Registration fee — '.($registration->payload['name'] ?? 'player'),
            );

            if (in_array($registration->status, [PlayerRegistration::STATUS_AWAITING_PAYMENT, PlayerRegistration::STATUS_EXPIRED], true)) {
                $this->registrations->addToPool($registration);
                $completed = true;
            } else {
                // Product decision: no auto-refund. Credit the organiser, flag it so
                // Collections shows it (isDuplicate) and they can refund it, alert ops.
                $payment->update(['is_duplicate' => true]);
                $this->alerts->send('Payment received for a registration that is already '.$registration->status, [
                    'registrationId' => $registration->id, 'registrationPaymentId' => $payment->id, 'razorpayPaymentId' => $razorpayPaymentId,
                ]);
            }

            return $payment->refresh();
        });

        if ($completed) {
            $this->registrations->closeOtherAttempts($result); // HTTP — after the money transaction committed
        }

        return $result;
    }
}
