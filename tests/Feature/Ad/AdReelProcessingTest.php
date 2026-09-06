<?php

declare(strict_types=1);

namespace Tests\Feature\Ad;

use App\Jobs\ProcessAdReel;
use App\Services\Ad\Support\AdMediaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class AdReelProcessingTest extends AdTestCase
{
    use RefreshDatabase;

    public function test_the_source_video_is_staged_on_shared_storage_not_the_local_disk(): void
    {
        Queue::fake();
        Storage::fake('local');

        $ad = $this->makeAd();

        app(AdMediaService::class)->queueReel($ad, UploadedFile::fake()->create('clip.mp4', 64, 'video/mp4'));

        $this->assertCount(1, Storage::disk(ProcessAdReel::TEMP_DISK)->files('temp_reels'));
        $this->assertSame([], Storage::disk('local')->allFiles());

        Queue::assertPushed(
            ProcessAdReel::class,
            fn (ProcessAdReel $job): bool => str_starts_with($job->videoPath, 'temp_reels/')
        );
    }

    public function test_a_missing_source_is_logged_and_does_not_create_a_reel(): void
    {
        $ad = $this->makeAd();

        (new ProcessAdReel($ad, 'temp_reels/gone.mp4'))->handle();

        $this->assertDatabaseCount('ad_reels', 0);
    }

    public function test_a_failed_run_discards_the_staged_source(): void
    {
        $ad = $this->makeAd();
        $path = 'temp_reels/clip.mp4';
        Storage::disk(ProcessAdReel::TEMP_DISK)->put($path, 'video-bytes');

        (new ProcessAdReel($ad, $path))->failed(new RuntimeException('cloudinary down'));

        $this->assertFalse(Storage::disk(ProcessAdReel::TEMP_DISK)->exists($path));
    }

    public function test_a_crash_mid_upload_leaves_no_local_temp_file_behind(): void
    {
        $ad = $this->makeAd();
        $path = 'temp_reels/clip.mp4';
        Storage::disk(ProcessAdReel::TEMP_DISK)->put($path, 'video-bytes');

        $before = $this->localTempReelFiles();

        try {
            (new ProcessAdReel($ad, $path))->handle();
        } catch (\Throwable) {
        }

        $this->assertSame($before, $this->localTempReelFiles());
        $this->assertTrue(
            Storage::disk(ProcessAdReel::TEMP_DISK)->exists($path),
            'The staged source must survive a retryable failure.'
        );
    }

    private function localTempReelFiles(): array
    {
        return glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'ad_reel_*') ?: [];
    }
}
