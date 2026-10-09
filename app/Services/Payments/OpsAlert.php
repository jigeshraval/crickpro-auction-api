<?php

namespace App\Services\Payments;

use App\Mail\OpsAlertMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/** Log (critical) + mail ops. Mail failures never break the money path. */
class OpsAlert
{
    public function send(string $subject, array $context = []): void
    {
        Log::critical("[payments] {$subject}", $context);

        $to = config('payments.ops_email');
        if (! $to) {
            return;
        }

        try {
            Mail::to($to)->send(new OpsAlertMail($subject, $context));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
