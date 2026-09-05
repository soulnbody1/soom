<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAdReelViewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ad_reel_id' => 'required|integer|exists:ad_reels,id',
        ];
    }

    public function messages(): array
    {
        return [
            'ad_reel_id.required' => 'رقم الإعلان مطلوب',
            'ad_reel_id.exists' => 'الإعلان غير موجود',
        ];
    }
}
