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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AttributeController extends Controller
{
    public function __construct(
        protected CategoryAttributeCache $attributes,
        protected CatalogCacheVersion $version,
    ) {}

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

    public function getOptionsByAttributeId($id)
    {
        $attribute = Attribute::with('options')->findOrFail($id);

        return response()->json([
            'success' => true,
            'parent-name' => $attribute->name,
            'data' => AttributeOptionResource::collection($attribute->options),
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
