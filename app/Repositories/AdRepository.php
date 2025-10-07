<?php

namespace App\Repositories;

use App\Models\Ad;
use App\Models\Category;
use Illuminate\Support\Facades\Storage;

class AdRepository
{
    public function create(array $data): Ad
    {
        return Ad::create($data);
    }

    public function attachAttributes(Ad $ad, array $attributes): void
    {
        foreach ($attributes as $attr) {
            $attributeId = $attr['id'];
            $value = $attr['value'];
            if (is_array($value)) {
                foreach ($value as $v) {
                    $ad->attributeValues()->create([
                        'attribute_id' => $attributeId,
                        'value' => $v
                    ]);
                }
            } else {
                $ad->attributeValues()->create([
                    'attribute_id' => $attributeId,
                    'value' => $value
                ]);
            }
        }
    }

    public function attachImages(Ad $ad, array $images): void
    {
        foreach ($images as $image) {
            $path = $image->store('ads', 'spaces');
            $ad->images()->create(['image_path' => $path]);
        }
    }
    public function getAdsByCategoryIds(array $categoryIds, ?object $user)
    {
        $query = Ad::whereIn('category_id', $categoryIds)
            ->with(Ad::$defaultRelations)
            ->latest()
            ->withIsFavorite($user);

        return $query->paginate(10);
    }

    public function getNearbyAds(array $categoryIds, $user)
    {
        $query = Ad::whereIn('category_id', $categoryIds)
            ->where(function ($query) use ($user) {
                if ($user->city_id) {
                    $query->orWhere(function ($q) use ($user) {
                        $q->where('city_id', $user->city_id);
                    });
                }
            })
            ->with(Ad::$defaultRelations)
            ->withIsFavorite($user)
            ->orderByRaw("FIELD(city_id, ?) DESC", [$user->city_id])
            ->latest()
            ->take(15);
        return $query->get()->unique('id');
    }

    public function getSubcategoriesWithAdCount($parentId)
    {
        return Category::select('id', 'name','image')
            ->where('parent_id', $parentId)
            ->withCount('ads')
            ->get();
    }

    public function getHomeAdsByCategoryIds(array $categoryIds, ?object $user)
    {
        $query = Ad::whereIn('category_id', $categoryIds)
            ->with(['images' => function ($q) {
                $q->select('ad_id', 'image_path')->limit(1);
            }])
            ->latest()
            ->withIsFavorite($user);

        $ads = $query->get()->map(function ($ad) {
            $ad->image = optional($ad->images->first())->image_path;
            unset($ad->images);
            return $ad;
        });

        return $ads;
    }

    public function update(Ad $ad, array $data): void
    {
        $ad->update($data);
    }

    public function syncAttributes(Ad $ad, array $attributes): void
    {
        $ad->attributeValues()->delete();
        $this->attachAttributes($ad, $attributes);
    }

    public function replaceImages(Ad $ad, array $images): void
    {
        if (!empty($images)) {
            // حذف الصور القديمة من التخزين ومن قاعدة البيانات
            foreach ($ad->images as $image) {
                Storage::disk('spaces')->delete($image->image_path);
                $image->delete();
            }

            $this->attachImages($ad, $images);
        }
    }
}
