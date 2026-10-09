<?php

namespace App\Console\Commands;

use App\Services\Payments\WithdrawalService;
use Illuminate\Console\Command;

class RecoverStuckPayouts extends Command
{
    protected $signature = 'payments:recover-payouts';

    protected $description = 'Re-send queued payouts that never reached RazorpayX (same idempotency key, so no double payment)';

    public function handle(WithdrawalService $withdrawals): int
    {
        $count = $withdrawals->recoverStuck();
        $this->info("Examined {$count} stuck payout(s).");

        return self::SUCCESS;
    }
}
