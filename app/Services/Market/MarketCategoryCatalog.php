<?php

declare(strict_types=1);

namespace App\Services\Market;

use App\Models\Category;
use App\Services\Ad\Support\CategoryTreeResolver;
use App\Services\Catalog\CatalogCacheVersion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final readonly class MarketCategoryCatalog
{
    public function __construct(
        private MarketCacheKey $keys,
        private CatalogCacheVersion $version,
        private CategoryTreeResolver $tree,
    ) {}

    /** @return list<int> */
    public function visibleIds(): array
    {
        return Cache::remember(
            $this->keys->market('catalog:visible-categories', $this->version->current()),
            3600,
            fn (): array => DB::table('market_category')
                ->where('market_id', app(\App\Support\Market\MarketContext::class)->marketId())
                ->where('is_visible', true)
                ->orderBy('display_order')
                ->pluck('category_id')
                ->map(static fn ($id): int => (int) $id)
                ->all()
        );
    }

    public function isVisible(int $categoryId): bool
    {
        return in_array($categoryId, $this->visibleIds(), true);
    }

    /** @return list<int> */
    public function subtreeIds(int $categoryId): array
    {
        if (! $this->isVisible($categoryId)) {
            return [];
        }

        $visible = array_flip($this->visibleIds());

        return array_values(array_filter(
            $this->tree->subtreeIds($categoryId),
            static fn (int $id): bool => isset($visible[$id])
        ));
    }

    public function roots(): Collection
    {
        return Category::query()
            ->select('categories.*')
            ->join('market_category', 'market_category.category_id', '=', 'categories.id')
            ->where('market_category.market_id', app(\App\Support\Market\MarketContext::class)->marketId())
            ->where('market_category.is_visible', true)
            ->whereNull('categories.parent_id')
            ->orderBy('market_category.display_order')
            ->orderBy('categories.id')
            ->get();
    }
}
