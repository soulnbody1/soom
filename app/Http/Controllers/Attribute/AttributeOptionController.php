<?php

namespace App\Http\Controllers\Attribute;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attribute\StoreAttributeOptionRequest;
use App\Http\Requests\Attribute\UpdateAttributeOptionRequest;
use App\Models\Attribute;
use App\Models\AttributeOption;
use App\Services\Catalog\CatalogCacheVersion;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Support\Facades\DB;

#[Group(name: 'خصائص الإعلانات', description: 'تعريف خصائص التصنيفات وخياراتها والتحكم في توريثها وظهورها داخل نماذج الإعلانات.', weight: 16)]
class AttributeOptionController extends Controller
{
    private const BETWEEN_OPTION_LIMIT = 2;

    public function __construct(protected CatalogCacheVersion $version) {}

    #[Endpoint(title: 'إضافة خيار إلى خاصية', description: 'ينشئ خيارًا جديدًا للخاصية مع تطبيق القيود الخاصة بنوع الخاصية.')]
    #[Response(200, description: 'بيانات الخيار بعد إنشائه.')]
    #[Response(422, description: 'لا يمكن إضافة الخيار بسبب قيود نوع الخاصية.')]
    public function store(StoreAttributeOptionRequest $request)
    {
        $option = DB::transaction(function () use ($request): ?AttributeOption {
            $attribute = Attribute::query()->lockForUpdate()->findOrFail($request->input('attribute_id'));

            if ($attribute->type === 'between'
                && $attribute->options()->count() >= self::BETWEEN_OPTION_LIMIT) {
                return null;
            }

            return AttributeOption::create($request->validated());
        });

        if ($option === null) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن إضافة أكثر من خيارين لهذا النوع (between)',
            ], 422);
        }

        $this->version->bump();

        return response()->json([
            'success' => true,
            'data' => $option,
        ]);
    }

    #[Endpoint(title: 'تحديث خيار خاصية', description: 'يحدّث بيانات الخيار المحدد للخاصية.')]
    #[PathParameter('id', description: 'المعرّف الرقمي لخيار الخاصية.')]
    #[Response(200, description: 'بيانات الخيار بعد التحديث.')]
    public function update(UpdateAttributeOptionRequest $request, $id)
    {
        $option = AttributeOption::findOrFail($id);
        $option->update($request->validated());

        $this->version->bump();

        return response()->json([
            'success' => true,
            'data' => $option,
        ]);
    }

    #[Endpoint(title: 'حذف خيار خاصية', description: 'يحذف الخيار المحدد ويحدّث نسخة الكتالوج المخزنة مؤقتًا.')]
    #[PathParameter('id', description: 'المعرّف الرقمي لخيار الخاصية.')]
    #[Response(200, description: 'تم حذف خيار الخاصية بنجاح.')]
    public function destroy($id)
    {
        $option = AttributeOption::findOrFail($id);
        $option->delete();

        $this->version->bump();

        return response()->json([
            'success' => true,
            'message' => 'Attribute option deleted successfully.',
        ]);
    }
}
