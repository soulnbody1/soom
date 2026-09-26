<?php

declare(strict_types=1);

namespace App\Services\Market;

use App\Models\Market;
use App\Support\Market\MarketContext;
use RuntimeException;

final readonly class MarketContextResolver
{
    public function __construct(private MarketContext $context) {}

    public function market(string $code): Market
    {
        $normalized = strtoupper(trim($code));

        return $this->context->runGlobally(function () use ($normalized): Market {
            return Market::query()->where('code', $normalized)->first()
                ?? throw new RuntimeException("Unknown market [{$normalized}].");
        });
    }
}
