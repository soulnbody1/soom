<?php

namespace App\Console\Commands;

use App\Services\Market\MarketDataMigrator;
use Illuminate\Console\Command;

final class ValidateMarkets extends Command
{
    protected $signature = 'market:validate';

    protected $description = 'Validate market ownership before contract constraints';

    public function handle(MarketDataMigrator $migrator): int
    {
        $issues = $migrator->validate();
        if ($issues === []) {
            $this->info('Market ownership validation passed.');

            return self::SUCCESS;
        }

        foreach ($issues as $issue => $count) {
            $this->error("{$issue}: {$count}");
        }

        return self::FAILURE;
    }
}
