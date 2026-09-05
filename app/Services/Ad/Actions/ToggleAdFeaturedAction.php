<?php

declare(strict_types=1);

namespace App\Services\Ad\Actions;

use App\Models\Ad;
use App\Services\Ad\Support\AdCacheVersion;

final class ToggleAdFeaturedAction
{
    public function __construct(private readonly AdCacheVersion $cacheVersion) {}

    public function execute(Ad $ad): bool
    {
        $ad->is_featured = ! $ad->is_featured;
        $ad->save();
        $this->cacheVersion->bump();

        return (bool) $ad->is_featured;
    }
}
