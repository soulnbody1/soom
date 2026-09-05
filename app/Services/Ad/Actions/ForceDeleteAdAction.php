<?php

declare(strict_types=1);

namespace App\Services\Ad\Actions;

use App\Models\Ad;
use App\Services\Ad\Support\AdCacheVersion;
use App\Services\Ad\Support\AdMediaService;
use Illuminate\Support\Facades\DB;

final class ForceDeleteAdAction
{
    public function __construct(
        private readonly AdMediaService $media,
        private readonly AdCacheVersion $cacheVersion,
    ) {}

    public function execute(Ad $ad): void
    {
        $storedPaths = $this->media->storedPathsOf($ad);

        DB::transaction(static fn () => $ad->forceDelete());

        $this->media->discard($storedPaths);
        $this->cacheVersion->bump();
    }
}
