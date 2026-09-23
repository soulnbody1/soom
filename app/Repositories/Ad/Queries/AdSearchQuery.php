<?php

declare(strict_types=1);

namespace App\Repositories\Ad\Queries;

use App\DTO\Ad\AdSearchDTO;
use App\Models\Ad;
use App\Models\Category;
use App\Services\Ad\Support\AdKeywordFilter;
use App\Services\Market\MarketCategoryCatalog;
use App\Services\Support\FulltextQuerySanitizer;
use Illuminate\Database\Eloquent\Builder;

final class AdSearchQuery
{
    public function __construct(
        private readonly AdKeywordFilter $keywords,
        private readonly FulltextQuerySanitizer $sanitizer,
        private readonly MarketCategoryCatalog $marketCategories,
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

        $query->whereIn('category_id', $this->marketCategories->subtreeIds((int) $category->id));
    }

    private function findCategory(string $keyword): ?Category
    {
        $expression = $this->sanitizer->toBooleanMode($keyword);

        return Category::query()
            ->select('id')
            ->whereIn('id', $this->marketCategories->visibleIds())
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
        $this->keywords->apply($query, $keyword);
    }
}
