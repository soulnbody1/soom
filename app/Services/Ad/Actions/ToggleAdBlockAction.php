<?php

declare(strict_types=1);

namespace App\Services\Ad\Actions;

use App\Models\Ad;
use App\Services\Ad\Support\AdCacheVersion;

final class ToggleAdBlockAction
{
    public function __construct(private readonly AdCacheVersion $cacheVersion) {}

    public function execute(Ad $ad): bool
    {
        $restored = $ad->trashed();

        $restored ? $ad->restore() : $ad->delete();
        $this->cacheVersion->bump();

        return $restored;
    }
}
