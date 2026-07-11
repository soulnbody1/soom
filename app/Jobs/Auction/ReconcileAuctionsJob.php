<?php

declare(strict_types=1);

namespace App\Jobs\Auction;

use App\Services\Auction\Actions\ReconcileAuctionsAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ReconcileAuctionsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function handle(ReconcileAuctionsAction $action): void
    {
        $action->execute();
    }
}
