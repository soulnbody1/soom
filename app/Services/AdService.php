<?php

namespace App\Services;

use App\Jobs\ProcessAdReel;
use App\Jobs\SendAdNotification;
use App\Repositories\AdRepository;
use App\Models\Ad;
use App\Models\AdView;
use App\Models\Category;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;



class AdService
{
    public function __construct(protected AdRepository $repo) {}

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
        Cache::forget('home_ads_data');
        return $ad;
    }
    public function getAdsWithCategoryAndNearby($categoryId, ?object $user)
    {
        $allCategories = Category::select('id', 'parent_id', 'name')->get()->groupBy('parent_id');
        $categoryIds = $this->getAllCategoryIdsEfficient($categoryId, $allCategories);

        return [
            'ads' => $this->repo->getAdsByCategoryIds($categoryIds, $user),
            'subcategories' => $this->repo->getSubcategoriesWithAdCount($categoryId),
            'nearby_ads' => $user && ($user->city_id)
                ? $this->repo->getNearbyAds($categoryIds, $user)
                : collect()
        ];
    }

    public function getHomeAds(?object $user)
    {
        $allCategories = Category::select('id', 'parent_id', 'name')->get()->groupBy('parent_id');
        $parentCategories = $allCategories->get(null, collect());
        $categoryIdsMap = [];
        foreach ($parentCategories as $category) {
            $categoryIdsMap[$category->id] = $this->getAllCategoryIdsEfficient($category->id, $allCategories);
        }
        $allCategoryIds = collect($categoryIdsMap)->flatten()->unique()->values()->toArray();
        $ads = $this->repo->getHomeAdsByCategoryIds($allCategoryIds, $user);
        $result = [];
        foreach ($parentCategories as $category) {
            $categoryIds = $categoryIdsMap[$category->id];
            $groupedAds = $ads->whereIn('category_id', $categoryIds)->take(4)->values();
            $result[] = [
                'category' => $category->name,
                'ads' => $groupedAds,
            ];
        }

        return $result;
    }

    protected function getAllCategoryIdsEfficient($parentId, $allCategories)
    {
        $ids = [$parentId];
        $children = $allCategories->get($parentId, collect());
        foreach ($children as $child) {
            $ids = array_merge($ids, $this->getAllCategoryIdsEfficient($child->id, $allCategories));
        }
        return $ids;
    }

    public function recordView(Ad $ad): void
    {
        $user = auth('sanctum')->user();
    
        if (!$user) {
            return;
        }
    
        AdView::firstOrCreate(
            [
                'ad_id' => $ad->id,
                'user_id' => $user->id,
            ],
            [
                'viewed_at' => now(),
            ]
        );
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
        Cache::forget('home_ads_data');
        return $ad;
    }

    public function getAdWithRelations(int $id, ?object $user = null): Ad
    {
        $query = Ad::with([
            'user:id,name,phone,logo',
            'category',
            'country',
            'state',
            'city',
            'images',
        ]);

        if ($user) {
            $query->withIsFavorite($user);
        }

        return $query->findOrFail($id);
    }

    public function getTrashedAdForUser(int $id): Ad
    {
        return Ad::withTrashed()
            ->where('id', $id)
            ->where('user_id', Auth::id())
            ->firstOrFail();
    }

    public function getMyAdsWithStats()
    {
        return Ad::withTrashed()
            ->where('user_id', Auth::id())
            ->withCount('views')
            ->with(Ad::$defaultRelations)
            ->latest()
            ->get();
    }
    protected function handleReelVideo(Ad $ad, $reel_video): void
    {
        if ($reel_video) {
            $tempPath = $reel_video->store('temp_reels', 'local');
            ProcessAdReel::dispatch($ad, $tempPath);
        }
    }
}