<?php

declare(strict_types=1);

namespace Database\Seeders\Concerns;

use App\Services\Market\MarketCommandRunner;
use Closure;
use Illuminate\Support\Facades\Schema;

trait SeedsInDefaultMarket
{
    /**
     * Seeders run from the console, where no request or job has established a
     * market. Baseline reference data belongs to the default market, so it is
     * opened explicitly rather than left to fail closed.
     */
    protected function inDefaultMarket(Closure $callback): void
    {
        if (! Schema::hasTable('markets')) {
            $callback();

            return;
        }

        app(MarketCommandRunner::class)->in(
            (string) config('markets.default_market_code'),
            static fn () => $callback()
        );
    }
}
