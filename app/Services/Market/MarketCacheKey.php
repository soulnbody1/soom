<?php

declare(strict_types=1);

namespace App\Services\Market;

use App\Support\Market\MarketContext;

final readonly class MarketCacheKey
{
    public function __construct(private MarketContext $context) {}

    public function market(string $namespace, string|int ...$parts): string
    {
        return implode(':', [
            'market',
            $this->context->marketId(),
            trim($namespace, ':'),
            ...array_map(static fn (string|int $part): string => (string) $part, $parts),
        ]);
    }

    public function global(string $namespace, string|int ...$parts): string
    {
        return implode(':', [
            'global',
            trim($namespace, ':'),
            ...array_map(static fn (string|int $part): string => (string) $part, $parts),
        ]);
    }
}
