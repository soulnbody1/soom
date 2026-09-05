<?php

declare(strict_types=1);

namespace App\Services\Ad\Actions;

use App\Models\Ad;
use App\Services\Ad\Support\AdCacheVersion;

final class DeleteAdAction
{
    public function __construct(private readonly AdCacheVersion $cacheVersion) {}

    public function execute(Ad $ad): void
    {
        $ad->delete();
        $this->cacheVersion->bump();
    }
}
