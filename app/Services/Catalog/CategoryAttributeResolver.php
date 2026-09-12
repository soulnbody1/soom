<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\Attribute;
use App\Services\Ad\Support\CategoryTreeResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class CategoryAttributeResolver
{
    public function __construct(private readonly CategoryTreeResolver $categories) {}

    public function resolve(int $categoryId): Collection
    {
        $lineage = $this->categories->ancestorIds($categoryId);
        $rank = array_flip($lineage);
        $ancestorIds = array_slice($lineage, 1);

        $attributes = Attribute::query()
            ->join('attribute_category', 'attributes.id', '=', 'attribute_category.attribute_id')
            ->select('attributes.*', 'attribute_category.category_id as inherited_from')
            ->where(function (Builder $query) use ($categoryId, $ancestorIds): void {
                $query->where('attribute_category.category_id', $categoryId);

                if ($ancestorIds !== []) {
                    $query->orWhere(fn (Builder $inherited): Builder => $inherited
                        ->whereIn('attribute_category.category_id', $ancestorIds)
                        ->where('attribute_category.is_inheritable', true));
                }
            })
            ->with('options')
            ->get()
            ->sortBy(static fn (Attribute $attribute): int => $rank[(int) $attribute->inherited_from] ?? PHP_INT_MAX)
            ->unique('id')
            ->values();

        return $this->withoutExceptions($attributes, $categoryId);
    }

    private function withoutExceptions(Collection $attributes, int $categoryId): Collection
    {
        if ($attributes->isEmpty()) {
            return $attributes;
        }

        $excluded = DB::table('attribute_category_exceptions')
            ->where('category_id', $categoryId)
            ->pluck('attribute_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($excluded === []) {
            return $attributes;
        }

        return $attributes
            ->reject(static fn (Attribute $attribute): bool => in_array((int) $attribute->id, $excluded, true))
            ->values();
    }
}
