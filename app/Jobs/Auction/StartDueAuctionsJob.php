<?php

declare(strict_types=1);

namespace App\Jobs\Auction;

use App\Contracts\Market\RunsAcrossMarkets;
use App\Jobs\Concerns\HasMarketJobContext;
use App\Services\Auction\Actions\StartDueAuctionsAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class StartDueAuctionsJob implements RunsAcrossMarkets, ShouldQueue
{
    use HasMarketJobContext, Queueable;

    public int $tries = 3;

    public function handle(StartDueAuctionsAction $action): void
    {
        $action->execute();
    }
}
