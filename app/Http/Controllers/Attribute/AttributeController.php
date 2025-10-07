<?php

namespace App\Http\Controllers\Attribute;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
use Illuminate\Http\Request;
use App\Http\Resources\AttributeResource;
use App\Http\Requests\Attribute\StoreAttributeRequest;
use App\Http\Requests\Attribute\UpdateAttributeRequest;
use App\Http\Requests\ExcludeAttributeFromCategoryRequest;
use App\Http\Requests\IncludeAttributeBackRequest;
use App\Http\Resources\AttributeOptionResource;
use App\Models\AttributeCategoryException;
use App\Models\Category;
use App\Traits\CachableAttribute;
use Illuminate\Support\Facades\DB;


class AttributeController extends Controller
{
    use CachableAttribute;

    public function getAttributesByCategory(Request $request)
    {
        $request->validate([
            'category_id' => 'required|exists:categories,id',
        ]);

        $data = $this->cacheAttributes($request->category_id);

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function store(StoreAttributeRequest $request)
    {
        $attribute = Attribute::create($request->validated());

        if ($request->filled('categories')) {
            $syncData = collect($request->categories)
                ->mapWithKeys(fn($cat) => [$cat['id'] => ['is_inheritable' => $cat['is_inheritable']]])
                ->toArray();

            $attribute->categories()->sync($syncData);

            foreach (array_keys($syncData) as $categoryId) {
                $this->clearAttributeCache($categoryId);
            }
        }

        return response()->json([
            'success' => true,
            'data' => new AttributeResource($attribute->load('categories')),
        ]);
    }

    public function update(UpdateAttributeRequest $request, $id)
    {
        $attribute = Attribute::findOrFail($id);
        $attribute->update($request->validated());
        if ($request->filled('categories')) {
            $syncData = collect($request->categories)
                ->mapWithKeys(fn($cat) => [$cat['id'] => ['is_inheritable' => $cat['is_inheritable']]])
                ->toArray();

            $attribute->categories()->sync($syncData);

            foreach (array_keys($syncData) as $categoryId) {
                $this->clearAttributeCache($categoryId);
            }
        }
        return response()->json([
            'success' => true,
            'data' => new AttributeResource($attribute->load('categories')),
        ]);
    }

    public function destroy($id)
    {
        $attribute = Attribute::findOrFail($id);//
        $this->clearAllAttributesCache();
        $attribute->delete();
        return response()->json([
            'success' => true,
            'message' => 'Attribute deleted successfully.',
        ]);
    }
    
    
    public function getOptionsByAttributeId($id)
    {
        $attribute = Attribute::with('options')->findOrFail($id);
        $options = AttributeOptionResource::collection($attribute->options);
        return response()->json([
            'success' => true,
            'parent-name' =>$attribute->name,
            'data' => $options,
        ]);
    }

    public function syncAttributesToCategory(Request $request)
    {
        $request->validate([
            'category_id' => 'required|exists:categories,id',
            'attributes' => 'required|array|min:1',
            'attributes.*.id' => 'required|exists:attributes,id',
            'attributes.*.is_inheritable' => 'required|boolean',
        ]);
        $category = Category::findOrFail($request->category_id);
        $syncData = collect($request->input('attributes'))
            ->mapWithKeys(fn($attr) => [$attr['id'] => ['is_inheritable' => $attr['is_inheritable']]])
            ->toArray();
        $category->attributes()->sync($syncData);
        $this->clearAttributeCache($category->id);
        return response()->json([
            'success' => true,
            'message' => 'Attributes synced to category successfully.',
        ]);
    }
    
        //------
    public function excludeAttributeFromCategory(ExcludeAttributeFromCategoryRequest $request)
    {
        $attached = DB::table('attribute_category')
            ->where('attribute_id', $request->attribute_id)
            ->where('category_id', $request->category_id)
            ->exists();

        if ($attached) {
            DB::table('attribute_category')
                ->where('attribute_id', $request->attribute_id)
                ->where('category_id', $request->category_id)
                ->delete();
        } else {
            AttributeCategoryException::updateOrInsert(
                [
                    'attribute_id' => $request->attribute_id,
                    'category_id' => $request->category_id,
                ],
                ['updated_at' => now(), 'created_at' => now()]
            );
        }

        $this->clearAttributeCache($request->category_id);
        return response()->json([
            'success' => true,
            'message' => 'تم الحذف بنجاح '
        ]);
    }

    public function includeAttributeBack(IncludeAttributeBackRequest $request)
    {
        AttributeCategoryException::where('attribute_id', $request->attribute_id)->where('category_id', $request->category_id)->delete();
        $this->clearAttributeCache($request->category_id);
        return response()->json([
            'success' => true,
            'message' => 'تم إعادة السماح بوراثة الـ attribute لهذه الفئة.'
        ]);
    }
}
