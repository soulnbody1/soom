<?php

namespace App\Http\Controllers\Ad;

use App\Models\Ad;
use App\Models\Category;
use App\Models\Country;
use App\Models\State;
use App\Models\City;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;

class AdSearch
{

    public function apply(Request $request): Builder
    {
        $query = Ad::query();
        $this->filterByCategory($request, $query);
        $this->filterByTitleOrLocation($request, $query);
        return $query;
    }


    protected function filterByCategory(Request $request, Builder $query): void
    {
        if (!$request->filled('category')) {
            return;
        }

        $categoryKeyword = $request->input('category');
        $category = Category::where(function ($q) use ($categoryKeyword) {
            $q->whereRaw("MATCH(name) AGAINST(? IN BOOLEAN MODE)", [$categoryKeyword])
                ->orWhere('name', 'LIKE', '%' . $categoryKeyword . '%');
        })->first();
        if (!$category) {
            $query->whereRaw('0 = 1');
            return;
        }
        $allCategories = Category::select('id', 'parent_id')->get()->groupBy('parent_id');
        $categoryIds = $this->getAllCategoryIdsEfficient($category->id, $allCategories);
        $query->whereIn('category_id', $categoryIds);
    }


    protected function filterByTitleOrLocation(Request $request, Builder $query): void
    {
        if (!$request->filled('title')) {
            return;
        }

        $keyword = trim($request->input('title'));

        $matchedCountryIds = Country::where('name', 'LIKE', "%{$keyword}%")->pluck('id')->toArray();
        $matchedStateIds   = State::where('name', 'LIKE', "%{$keyword}%")->pluck('id')->toArray();
        $matchedCityIds    = City::where('name', 'LIKE', "%{$keyword}%")->pluck('id')->toArray();

        $query->where(function ($q) use ($keyword, $matchedCountryIds, $matchedStateIds, $matchedCityIds) {
            $q->whereRaw("MATCH(title, description) AGAINST(? IN BOOLEAN MODE)", [$keyword])
                ->orWhere('title', 'LIKE', '%' . $keyword . '%')
                ->orWhere('description', 'LIKE', '%' . $keyword . '%');

            if (!empty($matchedCountryIds)) {
                $q->orWhereIn('country_id', $matchedCountryIds);
            }
            if (!empty($matchedStateIds)) {
                $q->orWhereIn('state_id', $matchedStateIds);
            }
            if (!empty($matchedCityIds)) {
                $q->orWhereIn('city_id', $matchedCityIds);
            }
        });
    }


    protected function getAllCategoryIdsEfficient($parentId, $allCategories): array
    {
        $ids = [$parentId];
        $children = $allCategories->get($parentId, collect());

        foreach ($children as $child) {
            $ids = array_merge($ids, $this->getAllCategoryIdsEfficient($child->id, $allCategories));
        }

        return $ids;
    }
}
