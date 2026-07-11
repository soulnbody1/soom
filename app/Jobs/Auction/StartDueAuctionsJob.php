<?php

declare(strict_types=1);

namespace App\Jobs\Auction;

use App\Application\Auction\Actions\StartDueAuctionsAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class StartDueAuctionsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function handle(StartDueAuctionsAction $action): void
    {
        $action->execute();
    }
}
