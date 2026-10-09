<?php

namespace App\Services\Payments;

use App\Models\PaymentDispute;
use App\Models\RegistrationPayment;
use App\Models\WalletLedger;

/** payment.dispute.* (spec §4): hold the organiser's share; release on won; book a loss on lost. */
class DisputeService
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly FeeCalculator $fees,
    ) {}

    public function onCreated(array $entity): string
    {
        $payment = RegistrationPayment::where('razorpay_payment_id', $entity['payment_id'] ?? '')->first();
        if (! $payment || empty($entity['id'])) {
            return 'ignored: unknown payment';
        }

        return $this->wallet->withLock($payment->id_owner, function () use ($payment, $entity) {
            if (PaymentDispute::where('razorpay_dispute_id', $entity['id'])->exists()) {
                return 'noop: dispute already held';
            }

            $payment = RegistrationPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            // Only the not-yet-refunded money can be charged back, and the
            // organiser can't lose more than what they still hold from this
            // payment (net − refunded share − holds already open / lost).
            $disputable = max(0, $payment->total_amount - $payment->refunded_amount);
            $amount = max(0, min((int) ($entity['amount'] ?? $disputable), $disputable));
            $alreadyHeld = (int) PaymentDispute::where('id_payment', $payment->id)
                ->whereIn('status', [PaymentDispute::STATUS_OPEN, PaymentDispute::STATUS_CLOSED, PaymentDispute::STATUS_LOST])
                ->sum('organiser_hold');
            $holdable = max(0, $payment->organiser_net - $payment->organiser_refunded - $alreadyHeld);
            $hold = min($this->fees->share($amount, $payment->total_amount, max(0, $payment->organiser_net)), $holdable);

            $dispute = PaymentDispute::create([
                'id_payment' => $payment->id,
                'razorpay_dispute_id' => $entity['id'],
                'amount' => $amount,
                'organiser_hold' => $hold,
                'status' => PaymentDispute::STATUS_OPEN,
            ]);
            if ($payment->status !== RegistrationPayment::STATUS_DISPUTE_LOST) {
                $payment->update(['status' => RegistrationPayment::STATUS_DISPUTED]);
            }

            $this->wallet->post($payment->id_owner, WalletLedger::TYPE_DISPUTE_HOLD, -$hold, now(),
                'payment_dispute', $dispute->id, "dispute_hold:{$dispute->razorpay_dispute_id}", 'Dispute hold — chargeback opened');
            $this->wallet->alertIfNegative($payment->id_owner);

            return 'dispute hold booked';
        });
    }

    public function onWon(array $entity): string
    {
        return $this->settle($entity, PaymentDispute::STATUS_WON, function (PaymentDispute $dispute, RegistrationPayment $payment) {
            $this->wallet->post($payment->id_owner, WalletLedger::TYPE_DISPUTE_RELEASE, $dispute->organiser_hold, now(),
                'payment_dispute', $dispute->id, "dispute_release:{$dispute->razorpay_dispute_id}", 'Dispute won — hold released');

            $this->restoreStatus($payment);
        });
    }

    public function onLost(array $entity): string
    {
        return $this->settle($entity, PaymentDispute::STATUS_LOST, function (PaymentDispute $dispute, RegistrationPayment $payment) {
            $this->wallet->post($payment->id_owner, WalletLedger::TYPE_DISPUTE_RELEASE, $dispute->organiser_hold, now(),
                'payment_dispute', $dispute->id, "dispute_release:{$dispute->razorpay_dispute_id}", 'Dispute lost — hold converted');
            $this->wallet->post($payment->id_owner, WalletLedger::TYPE_DISPUTE_LOSS, -$dispute->organiser_hold, now(),
                'payment_dispute', $dispute->id, "dispute_loss:{$dispute->razorpay_dispute_id}", 'Dispute lost — chargeback');

            $payment->update(['status' => RegistrationPayment::STATUS_DISPUTE_LOST]); // terminal — not refundable
        });
    }

    /** After won: dispute_lost if any dispute on it was lost, disputed while another is open, else paid/partially_refunded/refunded. */
    private function restoreStatus(RegistrationPayment $payment): void
    {
        $statuses = PaymentDispute::where('id_payment', $payment->id)->pluck('status')->all();

        $payment->update(['status' => match (true) {
            in_array(PaymentDispute::STATUS_LOST, $statuses, true) => RegistrationPayment::STATUS_DISPUTE_LOST,
            in_array(PaymentDispute::STATUS_OPEN, $statuses, true) => RegistrationPayment::STATUS_DISPUTED,
            default => $payment->settledStatus(),
        }]);
    }

    /** No ledger change: a closed dispute keeps its hold until won/lost arrives (ops follow-up). */
    public function onClosed(array $entity): string
    {
        $updated = PaymentDispute::where('razorpay_dispute_id', $entity['id'] ?? '')
            ->where('status', PaymentDispute::STATUS_OPEN)
            ->update(['status' => PaymentDispute::STATUS_CLOSED]);

        return $updated ? 'dispute closed' : 'ignored: no open dispute';
    }

    private function settle(array $entity, string $status, callable $book): string
    {
        $dispute = PaymentDispute::where('razorpay_dispute_id', $entity['id'] ?? '')->first();
        if (! $dispute) {
            return 'ignored: unknown dispute';
        }

        return $this->wallet->withLock($dispute->payment->id_owner, function () use ($dispute, $status, $book) {
            $dispute = PaymentDispute::whereKey($dispute->id)->lockForUpdate()->firstOrFail();
            if (! in_array($dispute->status, [PaymentDispute::STATUS_OPEN, PaymentDispute::STATUS_CLOSED], true)) {
                return 'noop: dispute already '.$dispute->status;
            }

            $dispute->update(['status' => $status]);
            $book($dispute, RegistrationPayment::whereKey($dispute->id_payment)->lockForUpdate()->firstOrFail());

            return 'dispute '.$status;
        });
    }
}
