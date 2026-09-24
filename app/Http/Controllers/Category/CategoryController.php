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
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

#[Group(name: 'التصنيفات', description: 'شجرة تصنيفات الإعلانات وإدارتها والتحكم في ظهورها داخل كل سوق.', weight: 15)]
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

    #[Endpoint(title: 'عرض التصنيفات', description: 'يعرض شجرة التصنيفات الظاهرة في السوق الحالي، ويمكن قصر النتيجة على التصنيفات الرئيسية.')]
    #[QueryParameter('parent', description: 'عند تمرير true تُعرض التصنيفات الرئيسية فقط.', type: 'boolean')]
    #[Response(200, description: 'قائمة التصنيفات أو شجرتها حسب الطلب.')]
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

    #[Endpoint(title: 'إنشاء تصنيف', description: 'ينشئ تصنيفًا جديدًا داخل شجرة التصنيفات.')]
    #[Response(200, description: 'بيانات التصنيف بعد إنشائه.')]
    public function store(StoreCategoryRequest $request)
    {
        return new CategoryResource($this->service->store($request->validated()));
    }

    #[Endpoint(title: 'عرض تصنيف', description: 'يعرض التصنيف المحدد وفروعه الظاهرة في السوق الحالي.')]
    #[PathParameter('category', description: 'المعرّف الرقمي للتصنيف.')]
    #[Response(200, description: 'بيانات التصنيف وفروعه.')]
    public function show(Category $category)
    {
        abort_unless($this->marketCategories->isVisible((int) $category->id), 404);

        return new CategoryResource($this->tree->withDescendants($category, $this->marketCategories->visibleIds()));
    }

    #[Endpoint(title: 'تحديث تصنيف', description: 'يحدّث بيانات التصنيف المحدد وموقعه داخل الشجرة.')]
    #[PathParameter('category', description: 'المعرّف الرقمي للتصنيف.')]
    #[Response(200, description: 'بيانات التصنيف بعد التحديث.')]
    public function update(UpdateCategoryRequest $request, Category $category)
    {
        return new CategoryResource($this->service->update($category, $request->validated()));
    }

    #[Endpoint(title: 'حذف تصنيف', description: 'يحذف التصنيف المحدد وفق قواعد سلامة شجرة التصنيفات.')]
    #[PathParameter('category', description: 'المعرّف الرقمي للتصنيف.')]
    #[Response(200, description: 'تم حذف التصنيف بنجاح.')]
    public function destroy(Category $category)
    {
        $this->service->delete($category);

        return response()->json(['message' => 'Category deleted.']);
    }

    #[Endpoint(title: 'تحديث ظهور تصنيف في السوق', description: 'يحدّث حالة ظهور التصنيف وترتيبه داخل السوق الحالي.')]
    #[PathParameter('category', description: 'المعرّف الرقمي للتصنيف.')]
    #[BodyParameter('is_visible', description: 'هل يظهر التصنيف في السوق الحالي.', required: true, type: 'boolean')]
    #[BodyParameter('display_order', description: 'ترتيب ظهور التصنيف، ويبدأ من صفر.', required: true, type: 'integer')]
    #[Response(200, description: 'حالة ظهور التصنيف وترتيبه بعد التحديث.')]
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
