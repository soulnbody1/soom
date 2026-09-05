<?php

declare(strict_types=1);

namespace App\Services\Ad\Actions;

use App\Models\Ad;
use App\Services\Ad\Support\AdCacheVersion;

final class RestoreAdAction
{
    public function __construct(private readonly AdCacheVersion $cacheVersion) {}

    public function execute(Ad $ad): void
    {
        $ad->restore();
        $this->cacheVersion->bump();
    }
}
