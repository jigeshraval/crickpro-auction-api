<?php

namespace App\Console\Commands;

use App\Services\SubscriptionService;
use Illuminate\Console\Command;

class ReconcileSubscriptions extends Command
{
    protected $signature = 'subscription:reconcile';

    protected $description = 'Backstop: activate pending razorpay subscriptions whose payment_link.paid webhook was missed';

    public function handle(SubscriptionService $subs): int
    {
        $count = $subs->reconcilePending();
        $this->info("Activated {$count} pending subscription(s).");

        return self::SUCCESS;
    }
}
