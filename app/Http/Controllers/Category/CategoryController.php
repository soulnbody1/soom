<?php

namespace App\Http\Controllers\Category;

use App\Http\Controllers\Controller;
use App\Http\Requests\Category\StoreCategoryRequest;
use App\Http\Requests\Category\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Services\CategoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CategoryController extends Controller
{
    public function __construct(protected CategoryService $service) {}

    public function index(Request $request)
    {
        $parentOnly = $request->boolean('parent');
        $key = $parentOnly ? 'categories_parents' : 'categories_all';

        $categories = Cache::remember($key, 3600, function () use ($parentOnly) {
            $query = Category::query()
                ->orderBy('display_order', 'asc')
                ->orderByRaw("CASE WHEN name = 'عقارات' THEN 0 ELSE 1 END")
                ->orderBy('id', 'asc');

            return $parentOnly
                ? $query->whereNull('parent_id')->get()
                : $query->whereNull('parent_id')->with('children')->get();
        });

        return CategoryResource::collection($categories);
    }


    public function store(StoreCategoryRequest $request)
    {
        $category = $this->service->store($request->validated());
        $this->clearCategoryCache();
        return new CategoryResource($category);
    }

    public function show(Category $category)
    {
        $category->load('children');
        return new CategoryResource($category);
    }

    public function update(UpdateCategoryRequest $request, Category $category)
    {
        $updated = $this->service->update($category, $request->validated());
        $this->clearCategoryCache();
        return new CategoryResource($updated);
    }

    public function destroy(Category $category)
    {
        $this->service->delete($category);
        $this->clearCategoryCache();
        return response()->json(['message' => 'Category deleted.']);
    }

    protected function clearCategoryCache(): void
    {
        Cache::forget('categories_parents');
        Cache::forget('categories_all');
    }
}
