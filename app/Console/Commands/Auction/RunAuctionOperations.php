<?php

declare(strict_types=1);

namespace App\Console\Commands\Auction;

use App\Jobs\Auction\DispatchAuctionOutboxJob;
use App\Jobs\Auction\FinalizeExpiredAuctionsJob;
use App\Jobs\Auction\RefundPendingAuctionDepositsJob;
use App\Jobs\Auction\StartDueAuctionsJob;
use Illuminate\Console\Command;

final class RunAuctionOperations extends Command
{
    protected $signature = 'auction:run-operations';

    protected $description = 'Dispatch due auction operational jobs.';

    public function handle(): int
    {
        StartDueAuctionsJob::dispatch();
        FinalizeExpiredAuctionsJob::dispatch();
        RefundPendingAuctionDepositsJob::dispatch();
        DispatchAuctionOutboxJob::dispatch();

        $this->info('Auction operational jobs dispatched.');

        return self::SUCCESS;
    }
}
