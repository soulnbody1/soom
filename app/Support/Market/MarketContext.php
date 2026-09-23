<?php

namespace App\Support\Market;

use App\Models\Market;
use Closure;
use Illuminate\Support\Facades\Context;
use LogicException;

final class MarketContext
{
    private const LOG_KEYS = ['market_mode', 'market_id', 'market_code'];

    private ?MarketState $state = null;

    public function initialize(MarketState $state): void
    {
        if ($this->state !== null) {
            throw new LogicException('Market context has already been initialized for this execution.');
        }

        $this->state = $state;
        $this->syncLogContext($state);
    }

    public function replace(MarketState $state): void
    {
        $this->state = $state;
        $this->syncLogContext($state);
    }

    public function initialized(): bool
    {
        return $this->state !== null;
    }

    public function state(): MarketState
    {
        return $this->state ?? throw new LogicException(
            'Market context has not been initialized. An HTTP request gets one from the market middleware '
            .'and a queued job from InitializeMarketContext; console code must open one itself with '
            .'MarketCommandRunner::in(), MarketContext::runInMarket() or MarketContext::runGlobally().'
        );
    }

    public function market(): Market
    {
        return $this->state()->market
            ?? throw new LogicException("Market context {$this->state()->mode->value} is global.");
    }

    public function marketId(): int
    {
        return (int) $this->market()->getKey();
    }

    public function run(MarketState $state, Closure $callback): mixed
    {
        $previous = $this->state;
        $previousLogContext = Context::only(self::LOG_KEYS);
        $this->state = $state;
        $this->syncLogContext($state);

        try {
            return $callback();
        } finally {
            $this->state = $previous;
            Context::forget(self::LOG_KEYS);
            Context::add($previousLogContext);
        }
    }

    public function runInMarket(Market|int $market, Closure $callback): mixed
    {
        if (is_int($market)) {
            $market = Market::query()->findOrFail($market);
        }

        return $this->run(MarketState::systemMarket($market), $callback);
    }

    public function runGlobally(Closure $callback): mixed
    {
        return $this->run(MarketState::systemGlobal(), $callback);
    }

    private function syncLogContext(MarketState $state): void
    {
        Context::forget(self::LOG_KEYS);
        Context::add('market_mode', $state->mode->value);

        if ($state->market !== null) {
            Context::add([
                'market_id' => (int) $state->market->getKey(),
                'market_code' => $state->market->code,
            ]);
        }
    }
}
