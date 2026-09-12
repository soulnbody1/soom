<?php

namespace App\Http\Requests\Category;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCategoryRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $parentId = $this->input('parent_id') === null ? null : (int) $this->input('parent_id');

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('categories')->where(fn ($query) => $query->where('parent_id', $parentId)),
            ],
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:3072',
            'display_order' => 'nullable|integer',
            'parent_id' => 'nullable|integer|exists:categories,id',
        ];
    }
}
