<?php

namespace App\Http\Controllers\Attribute;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attribute\StoreAttributeRequest;
use App\Http\Requests\Attribute\UpdateAttributeRequest;
use App\Http\Requests\ExcludeAttributeFromCategoryRequest;
use App\Http\Requests\IncludeAttributeBackRequest;
use App\Http\Resources\AttributeOptionResource;
use App\Http\Resources\AttributeResource;
use App\Models\Attribute;
use App\Models\AttributeCategoryException;
use App\Models\Category;
use App\Services\Catalog\CatalogCacheVersion;
use App\Services\Catalog\CategoryAttributeCache;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

#[Group(name: 'خصائص الإعلانات', description: 'تعريف خصائص التصنيفات وخياراتها والتحكم في توريثها وظهورها داخل نماذج الإعلانات.', weight: 16)]
class AttributeController extends Controller
{
    public function __construct(
        protected CategoryAttributeCache $attributes,
        protected CatalogCacheVersion $version,
    ) {}

    #[Endpoint(title: 'عرض خصائص تصنيف', description: 'يعرض الخصائص الفعالة للتصنيف المحدد بعد احتساب الخصائص الموروثة والاستثناءات.')]
    #[QueryParameter('category_id', description: 'المعرّف الرقمي للتصنيف.', required: true)]
    #[Response(200, description: 'قائمة الخصائص المتاحة للتصنيف.')]
    public function getAttributesByCategory(Request $request)
    {
        $request->validate([
            'category_id' => 'required|exists:categories,id',
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->attributes->forCategory((int) $request->input('category_id')),
        ]);
    }

    #[Endpoint(title: 'إنشاء خاصية', description: 'ينشئ خاصية جديدة ويمكن ربطها بمجموعة من التصنيفات وإعداد قابلية التوريث لكل ارتباط.')]
    #[Response(200, description: 'بيانات الخاصية بعد إنشائها مع التصنيفات والخيارات.')]
    public function store(StoreAttributeRequest $request)
    {
        $attribute = DB::transaction(function () use ($request): Attribute {
            $attribute = Attribute::create($request->validated());

            if ($request->filled('categories')) {
                $attribute->categories()->sync($this->pivotPayload($request->input('categories')));
            }

            return $attribute;
        });

        $this->version->bump();

        return response()->json([
            'success' => true,
            'data' => new AttributeResource($attribute->load('categories', 'options')),
        ]);
    }

    #[Endpoint(title: 'تحديث خاصية', description: 'يحدّث تعريف الخاصية وروابطها بالتصنيفات داخل عملية آمنة.')]
    #[PathParameter('id', description: 'المعرّف الرقمي للخاصية.')]
    #[Response(200, description: 'بيانات الخاصية بعد التحديث.')]
    public function update(UpdateAttributeRequest $request, $id)
    {
        $attribute = DB::transaction(function () use ($request, $id): Attribute {
            $attribute = Attribute::query()->lockForUpdate()->findOrFail($id);
            $attribute->update($request->validated());

            if ($request->filled('categories')) {
                $attribute->categories()->sync($this->pivotPayload($request->input('categories')));
            }

            return $attribute;
        });

        $this->version->bump();

        return response()->json([
            'success' => true,
            'data' => new AttributeResource($attribute->load('categories', 'options')),
        ]);
    }

    #[Endpoint(title: 'حذف خاصية', description: 'يحذف الخاصية المحددة ويحدّث نسخة ذاكرة التخزين المؤقت للكتالوج.')]
    #[PathParameter('id', description: 'المعرّف الرقمي للخاصية.')]
    #[Response(200, description: 'تم حذف الخاصية بنجاح.')]
    public function destroy($id)
    {
        $attribute = Attribute::findOrFail($id);
        $attribute->delete();

        $this->version->bump();

        return response()->json([
            'success' => true,
            'message' => 'Attribute deleted successfully.',
        ]);
    }

    #[Endpoint(title: 'عرض خيارات خاصية', description: 'يعرض اسم الخاصية وجميع الخيارات المعرفة لها.')]
    #[PathParameter('id', description: 'المعرّف الرقمي للخاصية.')]
    #[Response(200, description: 'اسم الخاصية وقائمة خياراتها.')]
    public function getOptionsByAttributeId($id)
    {
        $attribute = Attribute::with('options')->findOrFail($id);

        return response()->json([
            'success' => true,
            'parent-name' => $attribute->name,
            'data' => AttributeOptionResource::collection($attribute->options),
        ]);
    }

    #[Endpoint(title: 'مزامنة خصائص تصنيف', description: 'يستبدل روابط خصائص التصنيف بالقائمة المرسلة مع حفظ حالة التوريث لكل خاصية.')]
    #[Response(200, description: 'تمت مزامنة الخصائص مع التصنيف بنجاح.')]
    public function syncAttributesToCategory(Request $request)
    {
        $request->validate([
            'category_id' => 'required|exists:categories,id',
            'attributes' => 'required|array|min:1',
            'attributes.*.id' => 'required|exists:attributes,id',
            'attributes.*.is_inheritable' => 'required|boolean',
        ]);

        $category = Category::findOrFail($request->input('category_id'));

        DB::transaction(fn () => $category->attributes()->sync(
            $this->pivotPayload($request->input('attributes'))
        ));

        $this->version->bump();

        return response()->json([
            'success' => true,
            'message' => 'Attributes synced to category successfully.',
        ]);
    }

    #[Endpoint(title: 'استثناء خاصية من تصنيف', description: 'يمنع ظهور خاصية موروثة داخل تصنيف محدد دون حذف تعريف الخاصية الأصلي.')]
    #[Response(200, description: 'تم استثناء الخاصية من التصنيف.')]
    public function excludeAttributeFromCategory(ExcludeAttributeFromCategoryRequest $request)
    {
        AttributeCategoryException::firstOrCreate([
            'attribute_id' => $request->input('attribute_id'),
            'category_id' => $request->input('category_id'),
        ]);

        $this->version->bump();

        return response()->json([
            'success' => true,
            'message' => 'تم الحذف بنجاح ',
        ]);
    }

    #[Endpoint(title: 'إعادة خاصية إلى تصنيف', description: 'يلغي استثناء الخاصية لتعود إلى الظهور في التصنيف وفق قواعد التوريث.')]
    #[Response(200, description: 'تمت إعادة الخاصية إلى التصنيف.')]
    public function includeAttributeBack(IncludeAttributeBackRequest $request)
    {
        AttributeCategoryException::query()
            ->where('attribute_id', $request->input('attribute_id'))
            ->where('category_id', $request->input('category_id'))
            ->delete();

        $this->version->bump();

        return response()->json([
            'success' => true,
            'message' => 'تم إعادة السماح بوراثة الـ attribute لهذه الفئة.',
        ]);
    }

    private function pivotPayload(array $rows): array
    {
        return collect($rows)
            ->mapWithKeys(fn (array $row): array => [
                $row['id'] => ['is_inheritable' => $row['is_inheritable']],
            ])
            ->all();
    }
}
