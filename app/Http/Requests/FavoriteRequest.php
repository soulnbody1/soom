<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FavoriteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ad_id' => 'required|exists:ads,id',
        ];
    }

    public function messages(): array
    {
        return [
            'ad_id.required' => 'رقم الإعلان مطلوب',
            'ad_id.exists'   => 'هذا الإعلان غير موجود',
        ];
    }
}
