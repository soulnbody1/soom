<?php

declare(strict_types=1);

namespace App\Services\Ad\Support;

use App\Models\Category;
use Illuminate\Support\Facades\Cache;

final class CategoryTreeResolver
{
    private const CACHE_KEY = 'ads:category_tree';

    private const TTL_SECONDS = 604800;

    public function subtreeIds(int $categoryId): array
    {
        $children = $this->tree()['children'];
        $ids = [];
        $pending = [$categoryId];

        while ($pending !== []) {
            $current = array_pop($pending);
            $ids[] = $current;

            foreach ($children[$current] ?? [] as $child) {
                $pending[] = $child;
            }
        }

        return $ids;
    }

    public function ancestorIds(int $categoryId): array
    {
        $parents = $this->tree()['parents'];
        $ids = [$categoryId];
        $current = $categoryId;

        while (isset($parents[$current]) && ! in_array($parents[$current], $ids, true)) {
            $current = $parents[$current];
            $ids[] = $current;
        }

        return $ids;
    }

    public function roots(): array
    {
        return $this->tree()['roots'];
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function tree(): array
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (self::isWellFormed($cached)) {
            return $cached;
        }

        $tree = self::build();
        Cache::put(self::CACHE_KEY, $tree, self::TTL_SECONDS);

        return $tree;
    }

    private static function isWellFormed(mixed $tree): bool
    {
        return is_array($tree)
            && array_key_exists('children', $tree)
            && array_key_exists('parents', $tree)
            && array_key_exists('roots', $tree);
    }

    private static function build(): array
    {
        $children = [];
        $parents = [];
        $roots = [];

        Category::query()
            ->select('id', 'parent_id', 'name', 'display_order')
            ->orderBy('display_order')
            ->get()
            ->each(function (Category $category) use (&$children, &$parents, &$roots): void {
                if ($category->parent_id === null) {
                    $roots[] = ['id' => (int) $category->id, 'name' => $category->name];

                    return;
                }

                $children[(int) $category->parent_id][] = (int) $category->id;
                $parents[(int) $category->id] = (int) $category->parent_id;
            });

        return ['children' => $children, 'parents' => $parents, 'roots' => $roots];
    }
}
