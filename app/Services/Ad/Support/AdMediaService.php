<?php

declare(strict_types=1);

namespace App\Services\Ad\Support;

use App\Jobs\ProcessAdReel;
use App\Models\Ad;
use App\Models\AdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

final class AdMediaService
{
    private const DISK = 'spaces';

    private const DIRECTORY = 'ads';

    public function upload(array $images): array
    {
        return array_map(
            static fn (UploadedFile $image): string => $image->store(self::DIRECTORY, self::DISK),
            array_values($images)
        );
    }

    public function attach(Ad $ad, array $paths): void
    {
        if ($paths === []) {
            return;
        }

        $now = now();

        AdImage::insert(array_map(static fn (string $path): array => [
            'ad_id' => $ad->id,
            'image_path' => $path,
            'created_at' => $now,
            'updated_at' => $now,
        ], $paths));
    }

    public function storedPathsOf(Ad $ad): array
    {
        return $ad->images
            ->map(static fn (AdImage $image): string => ltrim((string) $image->getRawOriginal('image_path'), '/'))
            ->all();
    }

    public function discard(array $paths): void
    {
        foreach ($paths as $path) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    public function queueReel(Ad $ad, ?UploadedFile $reelVideo): void
    {
        if ($reelVideo === null) {
            return;
        }

        ProcessAdReel::dispatch($ad, $reelVideo->store('temp_reels', ProcessAdReel::TEMP_DISK));
    }
}
