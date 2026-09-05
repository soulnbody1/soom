<?php

declare(strict_types=1);

namespace App\Repositories\Ad\Queries;

use App\DTO\Ad\AdSearchDTO;
use App\Models\Ad;
use App\Models\Category;
use App\Services\Ad\Support\CategoryTreeResolver;
use App\Services\Ad\Support\FulltextQuerySanitizer;
use App\Services\Ad\Support\GeoNameResolver;
use Illuminate\Database\Eloquent\Builder;

final class AdSearchQuery
{
    public function __construct(
        private readonly CategoryTreeResolver $categories,
        private readonly GeoNameResolver $locations,
        private readonly FulltextQuerySanitizer $sanitizer,
    ) {}

    public function apply(AdSearchDTO $search): Builder
    {
        $query = Ad::query();

        if ($search->category !== null) {
            $this->filterByCategory($query, $search->category);
        }

        if ($search->keyword !== null) {
            $this->filterByKeyword($query, $search->keyword);
        }

        return $query;
    }

    private function filterByCategory(Builder $query, string $keyword): void
    {
        $category = $this->findCategory($keyword);

        if ($category === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereIn('category_id', $this->categories->subtreeIds((int) $category->id));
    }

    private function findCategory(string $keyword): ?Category
    {
        $expression = $this->sanitizer->toBooleanMode($keyword);

        return Category::query()
            ->select('id')
            ->where(function (Builder $group) use ($expression, $keyword): void {
                if ($expression !== null) {
                    $group->whereRaw('MATCH(name) AGAINST(? IN BOOLEAN MODE)', [$expression]);
                }

                $group->orWhere('name', 'LIKE', $this->sanitizer->toLikeContains($keyword));
            })
            ->first();
    }

    private function filterByKeyword(Builder $query, string $keyword): void
    {
        $expression = $this->sanitizer->toBooleanMode($keyword);
        $locations = $this->locations->matchingIds($keyword);

        $query->where(function (Builder $group) use ($expression, $keyword, $locations): void {
            if ($expression !== null) {
                $group->whereRaw('MATCH(title, description) AGAINST(? IN BOOLEAN MODE)', [$expression]);
            } else {
                $prefix = $this->sanitizer->toLikePrefix($keyword);
                $group->where('title', 'LIKE', $prefix)
                    ->orWhere('description', 'LIKE', $prefix);
            }

            foreach (['country_ids' => 'country_id', 'state_ids' => 'state_id', 'city_ids' => 'city_id'] as $key => $column) {
                if ($locations[$key] !== []) {
                    $group->orWhereIn($column, $locations[$key]);
                }
            }
        });
    }
}
