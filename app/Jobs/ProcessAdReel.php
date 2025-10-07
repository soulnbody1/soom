<?php

namespace App\Jobs;

use App\Models\Ad;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Cloudinary\Cloudinary;



class ProcessAdReel implements ShouldQueue
{
    use Dispatchable, Queueable, SerializesModels;

    public function __construct(
        protected Ad $ad,
        protected string $videoPath
    ) {}

    public function handle(): void
    {
        $localPath = Storage::disk('local')->path($this->videoPath);

        if (!file_exists($localPath)) {
            Log::error("❌ ملف الفيديو غير موجود: $localPath");
            return;
        }

        $cloudinary = new Cloudinary([
            'cloud' => [
                'cloud_name' => config('services.cloudinary.cloud_name'),
                'api_key'    => config('services.cloudinary.api_key'),
                'api_secret' => config('services.cloudinary.api_secret'),
            ],
        ]);
    
        $uploadApi = $cloudinary->uploadApi();

        // $uploadApi = new UploadApi();

        $uploadResponse = $uploadApi->upload($localPath, [
            'folder' => 'ads/reels',
            'resource_type' => 'video',
            'use_filename' => true,
            'unique_filename' => false,
            'eager' => [
                ['format' => 'jpg', 'start_offset' => '2'],
            ],
            'eager_async' => false,
        ]);

        $videoUrl = $uploadResponse['secure_url'] ?? null;
        $publicId = $uploadResponse['public_id'] ?? null; // e.g. "ads/reels/filename"
        $duration = $uploadResponse['duration'] ?? null;

        // استخراج اسم الملف فقط من public_id
        $filename = basename($publicId);
        // $cloudName = env('CLOUDINARY_CLOUD_NAME');
        $cloudName = config('services.cloudinary.cloud_name');

        // بناء رابط الصورة يدويًا باستخدام المسار الصحيح
        $thumbnailUrl = "https://res.cloudinary.com/{$cloudName}/video/upload/so_2/ads/reels/{$filename}.jpg";

        $this->ad->reel()->create([
            'video_path' => $videoUrl,
            'thumbnail_path' => $thumbnailUrl,
            'duration' => $duration,
        ]);

        unlink($localPath);
    }
}
