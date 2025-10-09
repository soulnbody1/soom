<?php

namespace App\Http\Requests\Category;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCategoryRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'name' => ['nullable', 'string', 'max:255', Rule::unique('categories')->ignore($this->route('category')->id)],
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:3072',
            'display_order' => 'nullable|integer',
            'parent_id' => 'nullable|exists:categories,id|not_in:' . $this->route('category')->id,
        ];
    }
}
