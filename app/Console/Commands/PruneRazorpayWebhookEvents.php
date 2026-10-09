<?php

namespace App\Console\Commands;

use App\Models\RazorpayWebhookEvent;
use Illuminate\Console\Command;

class PruneRazorpayWebhookEvents extends Command
{
    protected $signature = 'payments:prune-webhook-events';

    protected $description = 'Delete processed Razorpay webhook events older than payments.webhook_event_retention_days';

    public function handle(): int
    {
        $days = max(1, (int) config('payments.webhook_event_retention_days', 90));

        $cutoff = now()->subDays($days);
        $deleted = 0;

        do { // small batches keep row locks / undo short on a large table
            $ids = RazorpayWebhookEvent::whereNotNull('processed_at')->where('processed_at', '<', $cutoff)
                ->orderBy('id')->limit(1000)->pluck('id');
            $deleted += $ids->isEmpty() ? 0 : RazorpayWebhookEvent::whereIn('id', $ids)->delete();
        } while ($ids->count() === 1000);

        $this->info("Pruned {$deleted} webhook event(s).");

        return self::SUCCESS;
    }
}
