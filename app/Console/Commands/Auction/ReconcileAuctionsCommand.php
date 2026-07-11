<?php

declare(strict_types=1);

namespace App\Console\Commands\Auction;

use App\Services\Auction\Actions\ReconcileAuctionsAction;
use Illuminate\Console\Command;

final class ReconcileAuctionsCommand extends Command
{
    protected $signature = 'auction:reconcile';

    protected $description = 'Reconcile auction payments, refunds, settlements, and outbox messages.';

    public function handle(ReconcileAuctionsAction $action): int
    {
        $this->table(['Metric', 'Count'], collect($action->execute())->map(fn ($count, $metric) => [$metric, $count]));

        return self::SUCCESS;
    }
}
