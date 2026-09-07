<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IncludeAttributeBackRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'attribute_id' => 'required|exists:attributes,id',
            'category_id' => 'required|exists:categories,id',
        ];
    }
}
