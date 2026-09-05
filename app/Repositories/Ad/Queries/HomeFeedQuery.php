<?php

declare(strict_types=1);

namespace App\Repositories\Ad\Queries;

use App\Models\Ad;
use App\Models\AdImage;
use App\Services\Ad\Support\AdCacheVersion;
use App\Services\Ad\Support\CategoryTreeResolver;
use App\Services\Ad\Support\FavoriteFlagHydrator;
use Illuminate\Support\Facades\Cache;

final class HomeFeedQuery
{
    private const ADS_PER_CATEGORY = 4;

    private const TTL_SECONDS = 600;

    public function __construct(
        private readonly CategoryTreeResolver $categories,
        private readonly FavoriteFlagHydrator $favorites,
        private readonly AdCacheVersion $version,
    ) {}

    public function build(?object $viewer): array
    {
        $groups = Cache::remember(
            'ads:home:v'.$this->version->current(),
            self::TTL_SECONDS,
            fn (): array => $this->buildGroups()
        );

        return $this->applyViewerFavorites($groups, $viewer);
    }

    private function buildGroups(): array
    {
        $groups = [];
        $ads = [];

        foreach ($this->categories->roots() as $root) {
            $categoryAds = Ad::query()
                ->whereIn('category_id', $this->categories->subtreeIds($root['id']))
                ->latest()
                ->limit(self::ADS_PER_CATEGORY)
                ->get();

            foreach ($categoryAds as $ad) {
                $ads[] = $ad;
            }

            $groups[] = ['category' => $root['name'], 'ads' => $categoryAds];
        }

        $this->attachCoverImages($ads);

        return $groups;
    }

    private function attachCoverImages(array $ads): void
    {
        $adIds = array_map(static fn (Ad $ad): int => (int) $ad->id, $ads);

        $covers = $adIds === []
            ? []
            : AdImage::query()
                ->select('ad_id', 'image_path')
                ->whereIn('id', AdImage::query()->selectRaw('MIN(id)')->whereIn('ad_id', $adIds)->groupBy('ad_id'))
                ->get()
                ->keyBy('ad_id');

        foreach ($ads as $ad) {
            $ad->setAttribute('image', $covers[$ad->id]->image_path ?? null);
            $ad->setAttribute('is_favorite', false);
        }
    }

    private function applyViewerFavorites(array $groups, ?object $viewer): array
    {
        $adIds = [];

        foreach ($groups as $group) {
            foreach ($group['ads'] as $ad) {
                $adIds[] = (int) $ad->id;
            }
        }

        $favorited = $this->favorites->favoritedAdIds($viewer?->id, $adIds);

        foreach ($groups as $group) {
            foreach ($group['ads'] as $ad) {
                $ad->setAttribute('is_favorite', isset($favorited[(int) $ad->id]));
            }
        }

        return $groups;
    }
}
