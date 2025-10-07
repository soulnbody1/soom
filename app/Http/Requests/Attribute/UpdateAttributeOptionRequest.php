<?php

namespace App\Http\Requests\Attribute;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAttributeOptionRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'value' => 'sometimes|required|string|max:255',
            'label' => 'sometimes|required|string|max:255',
            'parent_option_id' => 'nullable|exists:attribute_options,id',
        ];
    }
}
