<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\Market\RunsInMarket;
use App\Jobs\Concerns\HasMarketJobContext;
use App\Models\Ad;
use Cloudinary\Cloudinary;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessAdReel implements RunsInMarket, ShouldQueue
{
    use Dispatchable, HasMarketJobContext, InteractsWithQueue, Queueable, SerializesModels;

    public const TEMP_DISK = 'spaces_private';

    public bool $deleteWhenMissingModels = true;

    public int $tries = 3;

    public array $backoff = [30, 120, 600];

    public int $timeout = 900;

    public int $maxExceptions = 2;

    public function __construct(
        Ad $ad,
        public readonly string $videoPath
    ) {
        $this->adId = (int) $ad->id;
        $this->marketId = (int) $ad->market_id;
    }

    public readonly int $adId;

    public readonly int $marketId;

    public function marketId(): int
    {
        return $this->marketId;
    }

    public function handle(): void
    {
        $ad = Ad::query()->find($this->adId);
        if ($ad === null) {
            $this->discardSource();

            return;
        }

        if ($ad->reel()->exists()) {
            $this->discardSource();

            return;
        }

        if (! Storage::disk(self::TEMP_DISK)->exists($this->videoPath)) {
            Log::error('Ad reel source missing', ['ad_id' => $ad->id, 'path' => $this->videoPath]);

            return;
        }

        $localPath = null;

        try {
            $localPath = $this->copyToLocalTemp();
            $upload = $this->uploadToCloudinary($localPath);

            $ad->reel()->updateOrCreate([], [
                'video_path' => $upload['secure_url'] ?? null,
                'thumbnail_path' => $this->thumbnailUrl($upload),
                'duration' => $upload['duration'] ?? null,
            ]);

            $this->discardSource();
        } finally {
            if ($localPath !== null && file_exists($localPath)) {
                @unlink($localPath);
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Ad reel processing failed', [
            'ad_id' => $this->adId,
            'path' => $this->videoPath,
            'error' => $exception?->getMessage(),
        ]);

        $this->discardSource();
    }

    private function copyToLocalTemp(): string
    {
        $localPath = tempnam(sys_get_temp_dir(), 'ad_reel_');
        $stream = Storage::disk(self::TEMP_DISK)->readStream($this->videoPath);

        if ($stream === null) {
            @unlink($localPath);

            throw new \RuntimeException('Unable to read ad reel source: '.$this->videoPath);
        }

        $target = fopen($localPath, 'wb');
        stream_copy_to_stream($stream, $target);
        fclose($target);
        fclose($stream);

        return $localPath;
    }

    private function uploadToCloudinary(string $localPath): array
    {
        $cloudinary = new Cloudinary([
            'cloud' => [
                'cloud_name' => config('services.cloudinary.cloud_name'),
                'api_key' => config('services.cloudinary.api_key'),
                'api_secret' => config('services.cloudinary.api_secret'),
            ],
        ]);

        return (array) $cloudinary->uploadApi()->upload($localPath, [
            'folder' => 'ads/reels',
            'resource_type' => 'video',
            'use_filename' => true,
            'unique_filename' => false,
            'eager' => [
                ['format' => 'jpg', 'start_offset' => '2'],
            ],
            'eager_async' => false,
        ]);
    }

    private function thumbnailUrl(array $upload): ?string
    {
        return $upload['eager'][0]['secure_url']
            ?? $upload['eager'][0]['url']
            ?? null;
    }

    private function discardSource(): void
    {
        try {
            Storage::disk(self::TEMP_DISK)->delete($this->videoPath);
        } catch (Throwable $exception) {
            Log::warning('Unable to discard ad reel source', [
                'path' => $this->videoPath,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
