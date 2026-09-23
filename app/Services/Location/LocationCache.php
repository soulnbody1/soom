<?php

declare(strict_types=1);

namespace App\Services\Location;

use App\Services\Market\MarketCacheKey;
use Illuminate\Support\Facades\Cache;

final class LocationCache
{
    private const PREFIX = 'locations:v';

    private const VERSION_KEY = 'locations:cache_version';

    private const TTL_SECONDS = 86400;

    public function __construct(private readonly MarketCacheKey $cacheKeys) {}

    public function remember(string $key, callable $callback): array
    {
        return Cache::remember(
            $this->cacheKeys->market(self::PREFIX, $this->version(), $key),
            self::TTL_SECONDS,
            $callback
        );
    }

    public function bump(): void
    {
        Cache::add(self::VERSION_KEY, 1);
        Cache::increment(self::VERSION_KEY);
    }

    private function version(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 1);
    }
}
