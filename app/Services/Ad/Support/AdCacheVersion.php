<?php

declare(strict_types=1);

namespace App\Services\Ad\Support;

use Illuminate\Support\Facades\Cache;

final class AdCacheVersion
{
    private const KEY = 'ads:cache_version';

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
