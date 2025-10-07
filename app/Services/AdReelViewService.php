<?php

namespace App\Services;

use App\Models\AdReelView;
use App\Models\Category;
use App\Repositories\AdReelViewRepository;
use Illuminate\Support\Facades\Auth;

class AdReelViewService
{
    protected $reelRepo;

    public function __construct(AdReelViewRepository $reelRepo)
    {
        $this->reelRepo = $reelRepo;
    }
    public function store(int $adReelId): AdReelView
    {
        $view = AdReelView::where('ad_reel_id', $adReelId)
            ->where('user_id', Auth::id())
            ->first();

        if ($view) {
            $view->wasRecentlyCreated = false;
            return $view;
        }
        $view = AdReelView::create([
            'ad_reel_id' => $adReelId,
            'user_id' => Auth::id(),
            'viewed_at' => now(),
        ]);
        $view->wasRecentlyCreated = true;
        return $view;
    }

    public function getReels(?object $user): array
    {
        return $this->reelRepo->getReelsForUser($user);
    }

    public function getReelsForCategory(?object $user, Category $category): array
    {
        $allCategories = Category::select('id', 'parent_id', 'name')->get()->groupBy('parent_id');
$categoryIds = $this->getAllCategoryIdsEfficient($category->id, $allCategories);
        return $this->reelRepo->getReelsForSingleCategory($user, $categoryIds);
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
}
