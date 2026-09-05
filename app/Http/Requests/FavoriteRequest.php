<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FavoriteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ad_id' => [
                'required',
                'integer',
                Rule::exists('ads', 'id')->whereNull('deleted_at'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'ad_id.required' => 'رقم الإعلان مطلوب',
            'ad_id.exists' => 'هذا الإعلان غير موجود',
        ];
    }
}
