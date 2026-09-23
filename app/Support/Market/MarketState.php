<?php

namespace App\Support\Market;

use App\Models\Market;
use InvalidArgumentException;

final readonly class MarketState
{
    private function __construct(
        public MarketMode $mode,
        public ?Market $market,
    ) {
        if ($mode->requiresMarket() !== ($market !== null)) {
            throw new InvalidArgumentException("Market mode {$mode->value} has an invalid market value.");
        }
    }

    public static function marketRequest(Market $market): self
    {
        return new self(MarketMode::MarketRequest, $market);
    }

    public static function accountGlobal(): self
    {
        return new self(MarketMode::AccountGlobal, null);
    }

    public static function adminMarket(Market $market): self
    {
        return new self(MarketMode::AdminMarket, $market);
    }

    public static function adminAll(): self
    {
        return new self(MarketMode::AdminAll, null);
    }

    public static function systemMarket(Market $market): self
    {
        return new self(MarketMode::SystemMarket, $market);
    }

    public static function systemGlobal(): self
    {
        return new self(MarketMode::SystemGlobal, null);
    }
}
