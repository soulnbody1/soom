<?php

namespace App\Traits;

use Illuminate\Support\Facades\Cache;
use App\Models\Category;
use App\Http\Resources\AttributeResource;
use Illuminate\Support\Facades\DB;

trait CachableAttribute
{
    protected function getCacheKey($categoryId)
    {
        return 'attributes_by_category_' . $categoryId;
    }

    // public function cacheAttributes($categoryId, $durationInDays = 7)
    // {
    //     $cacheKey = $this->getCacheKey($categoryId);
    //     $this->addCacheKeyToList($cacheKey, $durationInDays);
    //     return Cache::remember($cacheKey, now()->addDays($durationInDays), function () use ($categoryId) {
    //         $category = Category::findOrFail($categoryId);
    //         $attributes = $category->getAllAttributesWithInheritance();
    //         return AttributeResource::collection($attributes);
    //     });
    // }

    public function cacheAttributes($categoryId, $durationInDays = 7)
    {
        $cacheKey = $this->getCacheKey($categoryId);
        $this->addCacheKeyToList($cacheKey, $durationInDays);

        return Cache::remember($cacheKey, now()->addDays($durationInDays), function () use ($categoryId) {
            $category = Category::findOrFail($categoryId);
            $attributes = $category->getAllAttributesWithInheritance();
            $excluded = DB::table('attribute_category_exceptions')
                ->where('category_id', $categoryId)
                ->pluck('attribute_id')
                ->toArray();
            if (!empty($excluded)) {
                $attributes = $attributes->filter(function ($attr) use ($excluded) {
                    return ! in_array($attr->id, $excluded);
                });
            }
            return AttributeResource::collection($attributes);
        });
    }

    protected function addCacheKeyToList(string $key, int $durationInDays)
    {
        $listKey = 'attributes_cache_keys';
        $keys = Cache::get($listKey, []);

        if (!in_array($key, $keys)) {
            $keys[] = $key;
            Cache::put($listKey, $keys, now()->addDays($durationInDays));
        }
    }

    public function clearAllAttributesCache()
    {
        $listKey = 'attributes_cache_keys';
        $keys = Cache::get($listKey, []);

        foreach ($keys as $key) {
            Cache::forget($key);
        }

        Cache::forget($listKey);
    }

    public function clearAttributeCache($categoryId)
    {
        $cacheKey = $this->getCacheKey($categoryId);
        Cache::forget($cacheKey);

        $listKey = 'attributes_cache_keys';
        $keys = Cache::get($listKey, []);
        if (($index = array_search($cacheKey, $keys)) !== false) {
            unset($keys[$index]);
            Cache::put($listKey, array_values($keys));
        }
    }
}
