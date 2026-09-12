<?php

namespace App\Http\Controllers\Category;

use App\Http\Controllers\Controller;
use App\Http\Requests\Category\StoreCategoryRequest;
use App\Http\Requests\Category\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Services\Catalog\CatalogCacheVersion;
use App\Services\Catalog\CategoryTreeLoader;
use App\Services\CategoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CategoryController extends Controller
{
    private const TTL_SECONDS = 3600;

    public function __construct(
        protected CategoryService $service,
        protected CategoryTreeLoader $tree,
        protected CatalogCacheVersion $version,
    ) {}

    public function index(Request $request)
    {
        $parentOnly = $request->boolean('parent');
        $key = 'catalog:categories:v'.$this->version->current().':'.($parentOnly ? 'parents' : 'all');

        $categories = Cache::remember(
            $key,
            self::TTL_SECONDS,
            fn (): array => $this->render($parentOnly)
        );

        return response()->json(['data' => $categories]);
    }

    public function store(StoreCategoryRequest $request)
    {
        return new CategoryResource($this->service->store($request->validated()));
    }

    public function show(Category $category)
    {
        return new CategoryResource($this->tree->withDescendants($category));
    }

    public function update(UpdateCategoryRequest $request, Category $category)
    {
        return new CategoryResource($this->service->update($category, $request->validated()));
    }

    public function destroy(Category $category)
    {
        $this->service->delete($category);

        return response()->json(['message' => 'Category deleted.']);
    }

    private function render(bool $parentOnly): array
    {
        if ($parentOnly) {
            return CategoryResource::collection(
                Category::query()->whereNull('parent_id')->orderBy('display_order')->get()
            )->resolve(request());
        }

        return CategoryResource::collection($this->tree->roots())->resolve(request());
    }
}
