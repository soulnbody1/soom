<?php

declare(strict_types=1);

namespace App\Repositories\Ad\Queries;

use App\Models\Ad;
use App\Models\Category;
use App\Services\Ad\Support\CategoryTreeResolver;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;

final class CategoryFeedQuery
{
    private const PER_PAGE = 10;

    private const NEARBY_LIMIT = 15;

    public function __construct(private readonly CategoryTreeResolver $categories) {}

    public function build(int $categoryId, ?object $viewer): array
    {
        $categoryIds = $this->categories->subtreeIds($categoryId);

        return [
            'ads' => $this->paginateAds($categoryIds, $viewer),
            'subcategories' => $this->subcategories($categoryId),
            'nearby_ads' => $viewer?->city_id
                ? $this->nearbyAds($categoryIds, $viewer)
                : new BaseCollection,
        ];
    }

    private function paginateAds(array $categoryIds, ?object $viewer): LengthAwarePaginator
    {
        return Ad::query()
            ->whereIn('category_id', $categoryIds)
            ->with(Ad::$defaultRelations)
            ->latest()
            ->withIsFavorite($viewer)
            ->paginate(self::PER_PAGE);
    }

    private function nearbyAds(array $categoryIds, object $viewer): Collection
    {
        return Ad::query()
            ->whereIn('category_id', $categoryIds)
            ->where('city_id', $viewer->city_id)
            ->with(Ad::$defaultRelations)
            ->withIsFavorite($viewer)
            ->latest()
            ->limit(self::NEARBY_LIMIT)
            ->get();
    }

    private function subcategories(int $categoryId): Collection
    {
        $children = Category::query()
            ->select('id', 'name', 'image')
            ->where('parent_id', $categoryId)
            ->get();

        if ($children->isEmpty()) {
            return $children;
        }

        $owner = [];

        foreach ($children as $child) {
            foreach ($this->categories->subtreeIds((int) $child->id) as $descendantId) {
                $owner[$descendantId] = (int) $child->id;
            }
        }

        $counts = Ad::query()
            ->whereIn('category_id', array_keys($owner))
            ->groupBy('category_id')
            ->selectRaw('category_id, COUNT(*) as aggregate')
            ->pluck('aggregate', 'category_id');

        $totals = [];

        foreach ($counts as $categoryKey => $total) {
            $childId = $owner[(int) $categoryKey];
            $totals[$childId] = ($totals[$childId] ?? 0) + (int) $total;
        }

        return $children->each(
            static fn (Category $child) => $child->setAttribute('ads_count', $totals[(int) $child->id] ?? 0)
        );
    }
}
