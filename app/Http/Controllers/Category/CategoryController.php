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
use App\Services\Market\MarketCacheKey;
use App\Services\Market\MarketCategoryCatalog;
use App\Support\Market\MarketContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CategoryController extends Controller
{
    private const TTL_SECONDS = 3600;

    public function __construct(
        protected CategoryService $service,
        protected CategoryTreeLoader $tree,
        protected CatalogCacheVersion $version,
        protected MarketCacheKey $cacheKeys,
        protected MarketCategoryCatalog $marketCategories,
    ) {}

    public function index(Request $request)
    {
        $parentOnly = $request->boolean('parent');
        $key = $this->cacheKeys->market(
            'catalog:categories',
            $this->version->current(),
            $parentOnly ? 'parents' : 'all'
        );

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
        abort_unless($this->marketCategories->isVisible((int) $category->id), 404);

        return new CategoryResource($this->tree->withDescendants($category, $this->marketCategories->visibleIds()));
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

    public function updateMarketVisibility(Request $request, Category $category, MarketContext $context)
    {
        $data = $request->validate([
            'is_visible' => ['required', 'boolean'],
            'display_order' => ['required', 'integer', 'min:0'],
        ]);

        $category->markets()->syncWithoutDetaching([
            $context->marketId() => [
                'is_visible' => (bool) $data['is_visible'],
                'display_order' => (int) $data['display_order'],
            ],
        ]);
        $this->version->bump();

        return response()->json(['data' => [
            'category_id' => (int) $category->id,
            'market' => strtolower($context->market()->code),
            ...$data,
        ]]);
    }

    private function render(bool $parentOnly): array
    {
        if ($parentOnly) {
            return CategoryResource::collection(
                Category::query()
                    ->whereNull('parent_id')
                    ->whereIn('id', $this->marketCategories->visibleIds())
                    ->orderBy('display_order')
                    ->get()
            )->resolve(request());
        }

        return CategoryResource::collection($this->tree->roots($this->marketCategories->visibleIds()))->resolve(request());
    }
}
