<?php

namespace App\Services;

use App\Models\Category;
use Illuminate\Support\Facades\Storage;

class CategoryService
{
    public function store(array $data)
    {
        if (isset($data['image'])) {
            $data['image'] = $data['image']->store('categories', 'spaces');
        }

        return Category::create($data);
    }

    public function update(Category $category, array $data)
    {
        if (isset($data['image'])) {
            if ($category->image) {
                Storage::disk('spaces')->delete($category->image);
            }
            $data['image'] = $data['image']->store('categories', 'spaces');
        }

        $category->update($data);
        return $category;
    }

    public function delete(Category $category)
    {
        $this->deleteRecursive($category);
    }

    protected function deleteRecursive(Category $category)
    {
        foreach ($category->children as $child) {
            $this->deleteRecursive($child);
        }

        if ($category->image) {
            Storage::disk('spaces')->delete($category->image);
        }

        $category->delete();
    }
}
