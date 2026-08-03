<?php

declare(strict_types=1);

namespace App\Console\Commands\Auction;

use App\Jobs\Auction\AutoDefaultOverdueWinnersJob;
use App\Jobs\Auction\ExpireSellerDepositDeadlinesJob;
use App\Jobs\Auction\SendHandoverRemindersJob;
use App\Jobs\Auction\SendWinnerPaymentRemindersJob;
use Illuminate\Console\Command;

final class RunAuctionDeadlines extends Command
{
    protected $signature = 'auction:run-deadlines';

    protected $description = 'Dispatch auction deadline reminder and expiry jobs.';

    public function handle(): int
    {
        SendWinnerPaymentRemindersJob::dispatch();
        SendHandoverRemindersJob::dispatch();
        AutoDefaultOverdueWinnersJob::dispatch();
        ExpireSellerDepositDeadlinesJob::dispatch();

        $this->info('Auction deadline jobs dispatched.');

        return self::SUCCESS;
    }
}
