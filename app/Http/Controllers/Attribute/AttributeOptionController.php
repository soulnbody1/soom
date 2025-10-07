<?php

namespace App\Http\Controllers\Attribute;

use App\Http\Controllers\Controller;
use App\Models\AttributeOption;
use App\Http\Requests\Attribute\StoreAttributeOptionRequest;
use App\Http\Requests\Attribute\UpdateAttributeOptionRequest;
use App\Models\Attribute;
use App\Traits\CachableAttribute;


class AttributeOptionController extends Controller
{
    use CachableAttribute;

    public function store(StoreAttributeOptionRequest  $request)
    {
        $attribute = Attribute::findOrFail($request->attribute_id);

        if ($attribute->type === 'between') {
            $count = $attribute->options()->count();
            if ($count >= 2) {
                return response()->json([
                    'success' => false,
                    'message' => 'لا يمكن إضافة أكثر من خيارين لهذا النوع (between)',
                ], 422);
            }
        }
        $option = AttributeOption::create($request->validated());
        $this->clearAllAttributesCache();
        return response()->json([
            'success' => true,
            'data' => $option,
        ]);
    }

    public function update(UpdateAttributeOptionRequest $request, $id)
    {
        $option = AttributeOption::findOrFail($id);
        $option->update($request->validated());
        $this->clearAllAttributesCache();
        return response()->json([
            'success' => true,
            'data' => $option,
        ]);
    }

    public function destroy($id)
    {
        $option = AttributeOption::findOrFail($id);
        $option->delete();
        $this->clearAllAttributesCache();


        return response()->json([
            'success' => true,
            'message' => 'Attribute option deleted successfully.',
        ]);
    }
}
