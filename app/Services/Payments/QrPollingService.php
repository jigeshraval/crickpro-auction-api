<?php

namespace App\Services\Payments;

use App\Models\PlayerRegistration;
use App\Models\RegistrationPayment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Backstop for missed webhooks (spec §3.2 step 4): poll
 * GET /payments/qr_codes/{id}/payments and run the normal completion path.
 */
class QrPollingService
{
    public function __construct(
        private readonly RazorpayClient $razorpay,
        private readonly PaymentCompletionService $completion,
        private readonly RegistrationService $registrations,
    ) {}

    /** Called by GET v1/public/registrations/{token}. */
    public function refreshForStatus(PlayerRegistration $registration): void
    {
        if ($registration->mode !== PlayerRegistration::MODE_RAZORPAY
            || $registration->status !== PlayerRegistration::STATUS_AWAITING_PAYMENT) {
            return;
        }

        $payment = $registration->latestPayment;
        if (! $payment || $payment->status !== RegistrationPayment::STATUS_CREATED || ! $payment->razorpay_qr_id) {
            return;
        }

        $oldEnough = $payment->created_at->lte(now()->subSeconds((int) config('payments.poll_min_age_seconds')));

        if ($oldEnough && Cache::add("payments:qr-poll:{$payment->id}", true, (int) config('payments.poll_throttle_seconds'))) {
            $this->poll($payment);

            return;
        }

        $this->expireIfClosed($payment);
    }

    /** Scheduled every minute: open QRs + QRs closed within the grace window. */
    public function pollOpen(): int
    {
        $payments = RegistrationPayment::query()
            ->where('provider', RegistrationPayment::PROVIDER_RAZORPAY)
            ->whereIn('status', [RegistrationPayment::STATUS_CREATED, RegistrationPayment::STATUS_EXPIRED])
            ->whereNull('razorpay_payment_id')
            ->whereNotNull('razorpay_qr_id')
            ->where('created_at', '<=', now()->subSeconds((int) config('payments.poll_min_age_seconds')))
            ->where('qr_expires_at', '>', now()->subMinutes((int) config('payments.poll_grace_minutes')))
            ->orderBy('id')
            ->limit(200)
            ->get();

        foreach ($payments as $payment) {
            $this->poll($payment);
        }

        return $payments->count();
    }

    public function poll(RegistrationPayment $payment): void
    {
        try {
            $items = $this->razorpay->qrPayments($payment->razorpay_qr_id);
        } catch (RazorpayException $e) {
            Log::warning('Razorpay QR poll failed', ['registrationPaymentId' => $payment->id, 'error' => $e->getMessage()]);

            return;
        }

        foreach ($items as $entity) {
            if (($entity['status'] ?? null) === 'captured') {
                $this->completion->handleCapturedPayment($entity, $payment->razorpay_qr_id);
            }
        }

        $this->expireIfClosed($payment->refresh());
    }

    private function expireIfClosed(RegistrationPayment $payment): void
    {
        if ($payment->status === RegistrationPayment::STATUS_CREATED && $payment->qr_expires_at?->isPast()) {
            $this->registrations->expirePayment($payment);
        }
    }
}
