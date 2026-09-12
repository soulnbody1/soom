<?php

namespace App\Http\Requests\Category;

use App\Services\Ad\Support\CategoryTreeResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateCategoryRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $categoryId = $this->route('category')->id;

        return [
            'name' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('categories')
                    ->where(fn ($query) => $query->where('parent_id', $this->parentIdForUniqueness()))
                    ->ignore($categoryId),
            ],
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:3072',
            'display_order' => 'nullable|integer',
            'parent_id' => 'nullable|integer|exists:categories,id|not_in:'.$categoryId,
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('parent_id') || ! $this->filled('parent_id')) {
                return;
            }

            $categoryId = (int) $this->route('category')->id;
            $ancestors = app(CategoryTreeResolver::class)->ancestorIds((int) $this->input('parent_id'));

            if (in_array($categoryId, $ancestors, true)) {
                $validator->errors()->add('parent_id', 'لا يمكن نقل الفئة إلى واحدة من فئاتها الفرعية.');
            }
        });
    }

    private function parentIdForUniqueness(): ?int
    {
        if ($this->exists('parent_id')) {
            return $this->input('parent_id') === null ? null : (int) $this->input('parent_id');
        }

        $parentId = $this->route('category')->parent_id;

        return $parentId === null ? null : (int) $parentId;
    }
}
