<?php

namespace App\Console\Commands;

use App\Services\Payments\QrPollingService;
use Illuminate\Console\Command;

class PollRazorpayQrs extends Command
{
    protected $signature = 'payments:poll-qrs';

    protected $description = 'Backstop: poll Razorpay for payments on open / recently closed registration QRs';

    public function handle(QrPollingService $polling): int
    {
        $count = $polling->pollOpen();
        $this->info("Polled {$count} QR(s).");

        return self::SUCCESS;
    }
}
