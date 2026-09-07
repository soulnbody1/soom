<?php

namespace App\Http\Requests\Attribute;

use Illuminate\Foundation\Http\FormRequest;

class StoreAttributeRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'name' => 'required|string|max:255',
            'type' => 'required|string|in:text,number,select,boolean,checkbox,radio,textarea,between',
            'is_required' => 'boolean',
            'is_multiple' => 'boolean',
            'parent_attribute_id' => 'nullable|exists:attributes,id',

            'categories' => 'nullable|array',
            'categories.*.id' => 'required_with:categories|exists:categories,id',
            'categories.*.is_inheritable' => 'required_with:categories|boolean',
        ];
    }
}
