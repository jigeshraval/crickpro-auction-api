<?php

namespace App\Services\Payments;

/**
 * A Razorpay/RazorpayX call failed. httpStatus is null for timeouts/connection
 * errors AND for calls that were never sent (notSent: credentials missing, or
 * ApiGateway blocked the channel — disabled / over its hourly or daily limit).
 *
 * The message may contain Razorpay's raw response body — log it, never return
 * it to an API client (map to a generic PaymentRuleException message instead).
 */
class RazorpayException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly bool $notSent = false,
    ) {
        parent::__construct($message);
    }

    public static function notSent(string $message): self
    {
        return new self($message, null, true);
    }

    /**
     * Razorpay definitely did NOT act on the request: it was never sent, or it
     * was answered 4xx. 5xx/timeout = unknown outcome (may have been processed).
     */
    public function definitelyRejected(): bool
    {
        return $this->notSent
            || ($this->httpStatus !== null && $this->httpStatus >= 400 && $this->httpStatus < 500);
    }
}
