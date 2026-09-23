<?php

declare(strict_types=1);

namespace App\Jobs\Auction;

use App\Contracts\Market\RunsAcrossMarkets;
use App\Jobs\Concerns\HasMarketJobContext;
use App\Services\Auction\Actions\DispatchOutboxMessagesAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class DispatchAuctionOutboxJob implements RunsAcrossMarkets, ShouldQueue
{
    use HasMarketJobContext, Queueable;

    public int $tries = 5;

    public function handle(DispatchOutboxMessagesAction $action): void
    {
        $action->execute();
    }
}
