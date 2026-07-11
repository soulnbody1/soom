<?php

declare(strict_types=1);

namespace App\Jobs\Auction;

use App\Services\Auction\Actions\DispatchOutboxMessagesAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class DispatchAuctionOutboxJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function handle(DispatchOutboxMessagesAction $action): void
    {
        $action->execute();
    }
}
