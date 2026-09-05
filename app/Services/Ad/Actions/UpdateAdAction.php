<?php

declare(strict_types=1);

namespace App\Services\Ad\Actions;

use App\DTO\Ad\AdWriteInputDTO;
use App\Models\Ad;
use App\Services\Ad\Support\AdAttributeWriter;
use App\Services\Ad\Support\AdCacheVersion;
use App\Services\Ad\Support\AdMediaService;
use Illuminate\Support\Facades\DB;
use Throwable;

final class UpdateAdAction
{
    public function __construct(
        private readonly AdMediaService $media,
        private readonly AdAttributeWriter $attributes,
        private readonly AdCacheVersion $cacheVersion,
    ) {}

    public function execute(Ad $ad, AdWriteInputDTO $input): Ad
    {
        $replacingImages = $input->images !== [];
        $uploadedPaths = $replacingImages ? $this->media->upload($input->images) : [];
        $supersededPaths = [];

        try {
            DB::transaction(function () use ($ad, $input, $replacingImages, $uploadedPaths, &$supersededPaths): void {
                $ad->update($input->fields);
                $this->attributes->replace($ad, $input->attributes);

                if (! $replacingImages) {
                    return;
                }

                $supersededPaths = $this->media->storedPathsOf($ad);
                $ad->images()->delete();
                $this->media->attach($ad, $uploadedPaths);
            });
        } catch (Throwable $exception) {
            $this->media->discard($uploadedPaths);

            throw $exception;
        }

        $this->media->discard($supersededPaths);
        $this->media->queueReel($ad, $input->reelVideo);
        $this->cacheVersion->bump();

        return $ad;
    }
}
