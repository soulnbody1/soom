<?php

namespace App\Http\Requests\Attribute;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAttributeOptionRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'attribute_id' => 'required|exists:attributes,id',
            'value' => 'required|string|max:255',
            'label' => 'required|string|max:255',
            'parent_option_id' => [
                'nullable',
                'integer',
                Rule::exists('attribute_options', 'id')->where(
                    fn ($query) => $query->where('attribute_id', $this->input('attribute_id'))
                ),
            ],
        ];
    }
}
