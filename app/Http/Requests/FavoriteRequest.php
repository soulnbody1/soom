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
                'bail',
                'required',
                'string',
                'size:26',
                'ulid',
                Rule::exists('ads', 'public_id')->whereNull('deleted_at'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'ad_id.required' => 'معرّف الإعلان مطلوب',
            'ad_id.exists' => 'هذا الإعلان غير موجود',
        ];
    }
}
