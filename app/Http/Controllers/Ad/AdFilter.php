<?php

namespace App\Http\Controllers\Ad;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;

class AdFilter
{
    public function apply(Request $request, Builder $query): Builder
    {
        $this->filterPrice($request, $query);
        $this->filterLocation($request, $query);
        $this->filterAttributes($request, $query);
        $this->filterCategory($request, $query);

        return $query;
    }

    protected function filterPrice(Request $request, Builder $query): void
    {
        if ($request->filled('price_min')) {
            $query->where('price', '>=', $request->price_min);
        }

        if ($request->filled('price_max')) {
            $query->where('price', '<=', $request->price_max);
        }
    }

    protected function filterLocation(Request $request, Builder $query): void
    {
        foreach (['country_id', 'state_id', 'city_id'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->$field);
            }
        }
    }

    protected function filterAttributes(Request $request, Builder $query): void
    {
        $attributes = $request->input('attributes');
        if (!empty($attributes) && is_array($attributes)) {
            foreach ($attributes as $attributeId => $value) {
                $values = is_array($value) ? $value : explode(',', $value);
                $query->whereHas('attributeValues', function ($q) use ($attributeId, $values) {
                    $q->where('attribute_id', $attributeId)
                        ->whereIn('value', $values);
                });
            }
        }
    }

    protected function filterCategory(Request $request, Builder $query): void
    {
        if ($request->filled('category_id')) {
            $categoryId = $request->category_id;

            $allCategories = Category::select('id', 'parent_id')->get()->groupBy('parent_id');
            $categoryIds = $this->getAllCategoryIdsEfficient($categoryId, $allCategories);

            $query->whereIn('category_id', $categoryIds);
        }
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
