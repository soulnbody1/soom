<?php

namespace App\Http\Controllers\Attribute;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attribute\StoreAttributeOptionRequest;
use App\Http\Requests\Attribute\UpdateAttributeOptionRequest;
use App\Models\Attribute;
use App\Models\AttributeOption;
use App\Services\Catalog\CatalogCacheVersion;
use Illuminate\Support\Facades\DB;

class AttributeOptionController extends Controller
{
    private const BETWEEN_OPTION_LIMIT = 2;

    public function __construct(protected CatalogCacheVersion $version) {}

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
