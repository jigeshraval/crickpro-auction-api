<?php

namespace App\Services\Payments;

/**
 * Maps a verified, de-duplicated Razorpay/RazorpayX event to its handler.
 * Returns a short outcome string stored on razorpay_webhook_events.result.
 * Handlers for later features are resolved lazily via app() so this class's
 * constructor never changes as events are added.
 */
class WebhookRouter
{
    public function __construct(
        private readonly PaymentCompletionService $completion,
        private readonly RegistrationService $registrations,
    ) {}

    public function dispatch(string $source, string $event, array $payload): string
    {
        return match ($event) {
            'qr_code.credited' => $this->completion->handleCapturedPayment(
                (array) ($payload['payment']['entity'] ?? []),
                $payload['qr_code']['entity']['id'] ?? null,
            ) ? 'completed' : 'ignored: unknown payment',

            // close_reason "paid": the payment completes via qr_code.credited / poll — never expire it here.
            'qr_code.closed' => ($payload['qr_code']['entity']['close_reason'] ?? null) === 'paid'
                ? 'ignored: closed because paid'
                : ($this->registrations->expireQr((string) ($payload['qr_code']['entity']['id'] ?? ''))
                    ? 'expired' : 'ignored: unknown qr'),

            'refund.created' => app(RefundService::class)->onRefundCreated((array) ($payload['refund']['entity'] ?? [])),
            'refund.processed' => app(RefundService::class)->onRefundProcessed((array) ($payload['refund']['entity'] ?? [])),
            'refund.failed' => app(RefundService::class)->onRefundFailed((array) ($payload['refund']['entity'] ?? [])),

            'payment.dispute.created' => app(DisputeService::class)->onCreated((array) ($payload['dispute']['entity'] ?? [])),
            'payment.dispute.won' => app(DisputeService::class)->onWon((array) ($payload['dispute']['entity'] ?? [])),
            'payment.dispute.lost' => app(DisputeService::class)->onLost((array) ($payload['dispute']['entity'] ?? [])),
            'payment.dispute.closed' => app(DisputeService::class)->onClosed((array) ($payload['dispute']['entity'] ?? [])),

            'fund_account.validation.completed',
            'fund_account.validation.failed' => app(PayoutAccountService::class)->onValidationEvent((array) ($payload['fund_account.validation']['entity'] ?? [])),

            'payout.queued', 'payout.pending', 'payout.initiated', 'payout.updated',
            'payout.processed', 'payout.failed', 'payout.rejected', 'payout.reversed' => app(WithdrawalService::class)->onPayoutEvent($event, (array) ($payload['payout']['entity'] ?? [])),

            // Web subscription (team pack) purchase via a Razorpay Payment Link.
            'payment_link.paid' => app(\App\Services\SubscriptionService::class)->activateFromPaymentLink(
                (array) ($payload['payment_link']['entity'] ?? []),
                (array) ($payload['payment']['entity'] ?? []),
            ),

            default => 'ignored: unhandled event',
        };
    }
}
