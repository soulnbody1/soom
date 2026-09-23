<?php

namespace App\Console\Commands;

use App\Models\AdReel;
use App\Services\Market\MarketCommandRunner;
use Cloudinary\Api\Upload\UploadApi;
use Illuminate\Console\Command;

class DeleteOldAdReels extends Command
{
    protected $signature = 'reels:cleanup';

    protected $description = 'حذف ملفات الفيديو والصورة من Cloudinary بعد مرور 24 ساعة';

    public function handle(MarketCommandRunner $markets)
    {
        $deleted = 0;
        $markets->each(function () use (&$deleted): void {
            $expiredReels = AdReel::where('created_at', '<=', now()->subDay())->get();
            $uploadApi = new UploadApi;

            foreach ($expiredReels as $reel) {
                try {
                    $videoPublicId = $this->extractPublicId($reel->video_path);
                    $thumbPublicId = $this->extractPublicId($reel->thumbnail_path);

                    $this->info("🗑 حذف الفيديو: $videoPublicId");
                    $uploadApi->destroy($videoPublicId, ['resource_type' => 'video']);

                    $this->info("🗑 حذف الصورة: $thumbPublicId");
                    $uploadApi->destroy($thumbPublicId, ['resource_type' => 'image']);

                    $reel->delete();
                    $deleted++;
                } catch (\Exception $e) {
                    $this->error('❌ خطأ عند حذف الريل: '.$e->getMessage());
                }
            }
        });

        $this->info('✅ تم حذف '.$deleted.' من الـ Reels القديمة.');
    }

    private function extractPublicId($url)
    {
        $path = parse_url($url, PHP_URL_PATH); // مثلاً: /dbvxhv3t6/video/upload/ads/reels/xxx.mp4
        $pathParts = explode('/', $path);

        // نبدأ بعد جزء "upload" لأخذ ما بعده
        $startIndex = array_search('upload', $pathParts);
        if ($startIndex === false) {
            return null; // فشل
        }

        $relevantParts = array_slice($pathParts, $startIndex + 1); // ads/reels/xxx.mp4
        $filenameWithExt = array_pop($relevantParts); // xxx.mp4
        $filename = preg_replace('/\.(mp4|mov|jpg|jpeg|png)$/', '', $filenameWithExt); // xxx
        $relevantParts[] = $filename; // ads/reels/xxx

        return implode('/', $relevantParts); // ads/reels/xxx
    }
}
