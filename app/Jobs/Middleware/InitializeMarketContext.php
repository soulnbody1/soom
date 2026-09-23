<?php

namespace App\Jobs\Middleware;

use App\Contracts\Market\RunsAcrossMarkets;
use App\Contracts\Market\RunsGlobally;
use App\Contracts\Market\RunsInMarket;
use App\Models\Market;
use App\Support\Market\MarketContext;
use App\Support\Market\MarketState;
use LogicException;

final readonly class InitializeMarketContext
{
    public function __construct(private MarketContext $context) {}

    public function handle(object $job, callable $next): mixed
    {
        if ($job instanceof RunsInMarket) {
            return $this->context->run(
                MarketState::systemMarket(Market::query()->findOrFail($job->marketId())),
                fn () => $next($job)
            );
        }

        if ($job instanceof RunsAcrossMarkets) {
            return $this->context->run(MarketState::systemGlobal(), function () use ($job, $next): mixed {
                $result = null;
                $marketIds = Market::query()->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();

                foreach ($marketIds as $marketId) {
                    $result = $this->context->runInMarket($marketId, fn () => $next($job));
                }

                return $result;
            });
        }

        if ($job instanceof RunsGlobally) {
            return $this->context->run(MarketState::systemGlobal(), fn () => $next($job));
        }

        throw new LogicException('Queued market work must implement RunsInMarket or RunsAcrossMarkets.');
    }
}
