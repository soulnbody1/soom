<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use Illuminate\Support\Facades\Cache;

final class CatalogCacheVersion
{
    private const KEY = 'catalog:cache_version';

    public function current(): int
    {
        return (int) Cache::get(self::KEY, 1);
    }

    public function bump(): void
    {
        Cache::add(self::KEY, 1);
        Cache::increment(self::KEY);
    }
}
