<?php

namespace App\Console\Commands;

use App\Services\Market\MarketDataMigrator;
use Illuminate\Console\Command;

final class BackfillMarkets extends Command
{
    protected $signature = 'market:backfill';

    protected $description = 'Idempotently backfill market ownership for historical data';

    public function handle(MarketDataMigrator $migrator): int
    {
        foreach ($migrator->backfill() as $table => $count) {
            $this->line("{$table}: {$count}");
        }

        return self::SUCCESS;
    }
}
