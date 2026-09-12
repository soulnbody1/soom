<?php

namespace App\Http\Requests\Attribute;

use App\Models\AttributeOption;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'parent_option_id' => [
                'nullable',
                'integer',
                Rule::exists('attribute_options', 'id')->where(
                    fn ($query) => $query->where('attribute_id', $this->optionAttributeId())
                ),
                'not_in:'.$this->route('id'),
            ],
        ];
    }

    private function optionAttributeId(): ?int
    {
        $attributeId = AttributeOption::query()->whereKey($this->route('id'))->value('attribute_id');

        return $attributeId === null ? null : (int) $attributeId;
    }
}
