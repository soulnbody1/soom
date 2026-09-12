<?php

namespace App\Services;

use App\Models\Ad;
use App\Models\Category;
use App\Services\Ad\Support\CategoryTreeResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class CategoryService
{
    public function __construct(private readonly CategoryTreeResolver $tree) {}

    public function store(array $data)
    {
        if (isset($data['image'])) {
            $data['image'] = $data['image']->store('categories', 'spaces');
        }

        return Category::create($data);
    }

    public function update(Category $category, array $data)
    {
        $previousImage = null;

        if (isset($data['image'])) {
            $previousImage = $category->getRawOriginal('image');
            $data['image'] = $data['image']->store('categories', 'spaces');
        }

        $category->update($data);

        if ($previousImage) {
            Storage::disk('spaces')->delete(ltrim($previousImage, '/'));
        }

        return $category;
    }

    public function delete(Category $category): void
    {
        $subtreeIds = $this->tree->subtreeIds((int) $category->id);

        $this->guardAgainstContent($subtreeIds);

        $images = Category::query()
            ->whereIn('id', $subtreeIds)
            ->whereNotNull('image')
            ->pluck('image')
            ->all();

        $descendantIds = array_values(array_diff($subtreeIds, [(int) $category->id]));

        DB::transaction(function () use ($category, $descendantIds): void {
            if ($descendantIds !== []) {
                Category::query()->whereIn('id', $descendantIds)->delete();
            }

            $category->delete();
        });

        foreach ($images as $image) {
            Storage::disk('spaces')->delete(ltrim($image, '/'));
        }
    }

    private function guardAgainstContent(array $subtreeIds): void
    {
        if (Ad::withTrashed()->whereIn('category_id', $subtreeIds)->exists()) {
            throw ValidationException::withMessages([
                'category' => 'لا يمكن حذف فئة تحتوي على إعلانات.',
            ]);
        }

        if (DB::table('auctions')->whereIn('category_id', $subtreeIds)->exists()) {
            throw ValidationException::withMessages([
                'category' => 'لا يمكن حذف فئة تحتوي على مزادات.',
            ]);
        }
    }
}
