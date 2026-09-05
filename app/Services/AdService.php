<?php

namespace App\Services;

use App\Jobs\ProcessAdReel;
use App\Jobs\SendAdNotification;
use App\Models\Ad;
use App\Models\AdView;
use App\Repositories\AdRepository;
use App\Services\Ad\Support\AdCacheVersion;
use Illuminate\Support\Facades\Auth;

class AdService
{
    public function __construct(
        protected AdRepository $repo,
        protected AdCacheVersion $cacheVersion,
    ) {}

    public function store(array $data): Ad
    {
        $attributes = $data['attributes'] ?? [];
        $images = $data['images'] ?? [];
        $reel_video = $data['reel_video'] ?? null;

        unset($data['attributes'], $data['images'], $data['reel_video']);

        $ad = $this->repo->create($data);
        $this->repo->attachAttributes($ad, $attributes);
        $this->repo->attachImages($ad, $images);
        $this->handleReelVideo($ad, $reel_video);
        SendAdNotification::dispatch($ad);
        $this->cacheVersion->bump();

        return $ad;
    }

    public function update(Ad $ad, array $data): Ad
    {
        $attributes = $data['attributes'] ?? [];
        $images = $data['images'] ?? [];
        $reel_video = $data['reel_video'] ?? null;

        unset($data['attributes'], $data['images'], $data['reel_video']);

        $this->repo->update($ad, $data);
        $this->repo->syncAttributes($ad, $attributes);
        $this->repo->replaceImages($ad, $images);
        $this->handleReelVideo($ad, $reel_video);
        $this->cacheVersion->bump();

        return $ad;
    }

    public function recordView(Ad $ad): void
    {
        $user = auth('sanctum')->user();

        if (! $user) {
            return;
        }

        AdView::firstOrCreate(
            ['ad_id' => $ad->id, 'user_id' => $user->id],
            ['viewed_at' => now()],
        );
    }

    public function getTrashedAdForUser(int $id): Ad
    {
        return Ad::withTrashed()
            ->where('id', $id)
            ->where('user_id', Auth::id())
            ->firstOrFail();
    }

    public function getTrashedAd(int $id): Ad
    {
        return Ad::withTrashed()->where('id', $id)->firstOrFail();
    }

    protected function handleReelVideo(Ad $ad, $reel_video): void
    {
        if ($reel_video) {
            $tempPath = $reel_video->store('temp_reels', 'local');
            ProcessAdReel::dispatch($ad, $tempPath);
        }
    }
}
