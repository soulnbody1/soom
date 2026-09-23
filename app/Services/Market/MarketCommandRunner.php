<?php

declare(strict_types=1);

namespace App\Services\Market;

use App\Models\Market;
use App\Support\Market\MarketContext;
use App\Support\Market\MarketState;
use Closure;
use InvalidArgumentException;

final readonly class MarketCommandRunner
{
    public function __construct(private MarketContext $context) {}

    public function in(string $code, Closure $callback): mixed
    {
        $market = Market::query()->where('code', strtoupper(trim($code)))->first();
        if ($market === null) {
            throw new InvalidArgumentException("Unknown market [{$code}].");
        }

        return $this->context->run(MarketState::systemMarket($market), fn () => $callback($market));
    }

    public function each(Closure $callback): array
    {
        return $this->context->run(MarketState::systemGlobal(), function () use ($callback): array {
            $results = [];
            $ids = Market::query()->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id);

            foreach ($ids as $id) {
                $market = Market::query()->findOrFail($id);
                $results[$market->code] = $this->context->run(
                    MarketState::systemMarket($market),
                    fn () => $callback($market)
                );
            }

            return $results;
        });
    }
}
