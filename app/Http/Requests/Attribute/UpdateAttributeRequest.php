<?php

namespace App\Http\Requests\Attribute;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAttributeRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }


    public function rules()
    {
        return [
            'name' => 'sometimes|required|string|max:255',
            'type' => 'sometimes|required|string|in:text,number,select,checkbox,radio,textarea,between',
            'is_required' => 'sometimes|boolean',
            'is_multiple' => 'sometimes|boolean',
            'parent_attribute_id' => 'nullable|exists:attributes,id',

            'categories' => 'sometimes|array',
            'categories.*.id' => 'required_with:categories|exists:categories,id',
            'categories.*.is_inheritable' => 'required_with:categories|boolean',
        ];
    }
}
