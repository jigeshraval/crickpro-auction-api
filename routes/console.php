<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Registration payments: catch Razorpay QR payments whose webhook was missed.
Schedule::command('payments:poll-qrs')->everyMinute()->withoutOverlapping();

// Registration payments: re-send payouts stuck in `queued` with no RazorpayX id (idempotent).
Schedule::command('payments:recover-payouts')->everyFiveMinutes()->withoutOverlapping();

// Registration payments: drop processed webhook events older than payments.webhook_event_retention_days.
Schedule::command('payments:prune-webhook-events')->daily()->withoutOverlapping();
