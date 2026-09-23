<?php

declare(strict_types=1);

namespace App\Console\Commands\Auction;

use App\Services\Auction\Actions\ReconcileAuctionsAction;
use App\Services\Market\MarketCommandRunner;
use Illuminate\Console\Command;

final class ReconcileAuctionsCommand extends Command
{
    protected $signature = 'auction:reconcile';

    protected $description = 'Reconcile auction payments, refunds, settlements, and outbox messages.';

    public function handle(ReconcileAuctionsAction $action, MarketCommandRunner $markets): int
    {
        foreach ($markets->each(fn () => $action->execute()) as $market => $report) {
            $this->info("Market: {$market}");
            $this->table(['Metric', 'Count'], collect($report)->map(fn ($count, $metric) => [$metric, $count]));
        }

        return self::SUCCESS;
    }
}
