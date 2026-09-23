<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\Category;
use Illuminate\Database\Eloquent\Collection;

final class CategoryTreeLoader
{
    public function roots(?array $allowedIds = null): Collection
    {
        $byParent = $this->byParent($allowedIds);

        return new Collection(array_map(
            fn (Category $root): Category => $this->hydrate($root, $byParent, []),
            $byParent[0] ?? []
        ));
    }

    public function withDescendants(Category $category, ?array $allowedIds = null): Category
    {
        return $this->hydrate($category, $this->byParent($allowedIds), []);
    }

    /**
     * @param  array<int, list<Category>>  $byParent
     * @param  list<int>  $seen
     */
    private function hydrate(Category $category, array $byParent, array $seen): Category
    {
        $id = (int) $category->id;

        if (in_array($id, $seen, true)) {
            return $category->setRelation('children', new Collection);
        }

        $seen[] = $id;

        return $category->setRelation('children', new Collection(array_map(
            fn (Category $child): Category => $this->hydrate($child, $byParent, $seen),
            $byParent[$id] ?? []
        )));
    }

    /**
     * @return array<int, list<Category>>
     */
    private function byParent(?array $allowedIds = null): array
    {
        $byParent = [];

        $query = Category::query()->orderBy('display_order');
        if ($allowedIds !== null) {
            $query->whereIn('id', $allowedIds);
        }

        foreach ($query->get() as $category) {
            $byParent[(int) $category->parent_id][] = $category;
        }

        return $byParent;
    }
}
