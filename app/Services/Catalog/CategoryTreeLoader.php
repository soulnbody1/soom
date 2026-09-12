<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\Category;
use Illuminate\Database\Eloquent\Collection;

final class CategoryTreeLoader
{
    public function roots(): Collection
    {
        $byParent = $this->byParent();

        return new Collection(array_map(
            fn (Category $root): Category => $this->hydrate($root, $byParent, []),
            $byParent[0] ?? []
        ));
    }

    public function withDescendants(Category $category): Category
    {
        return $this->hydrate($category, $this->byParent(), []);
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
    private function byParent(): array
    {
        $byParent = [];

        foreach (Category::query()->orderBy('display_order')->get() as $category) {
            $byParent[(int) $category->parent_id][] = $category;
        }

        return $byParent;
    }
}
