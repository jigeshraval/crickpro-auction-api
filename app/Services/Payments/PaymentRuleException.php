<?php

namespace App\Services\Payments;

use Illuminate\Http\JsonResponse;

/** A business-rule rejection shown to the user. Renders itself; never reported to Sentry. */
class PaymentRuleException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(['status' => 'error', 'message' => $this->getMessage()], $this->status);
    }

    /** Returning non-false marks it handled → not sent to the default reporter. */
    public function report(): void {}
}
