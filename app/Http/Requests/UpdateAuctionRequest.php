<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Contracts\Validation\Validator;

class UpdateAuctionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return request()->bearerToken() !== null && auth('sanctum')->check();
    }

    protected function failedAuthorization()
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Unauthenticated.',
            'error' => 'يجب تسجيل الدخول أولاً.'
        ], 401));
    }

    public function rules(): array
    {
        return [
            // بيانات المزاد - كلها اختيارية في التحديث
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string|min:50',
            'category_id' => 'nullable|exists:categories,id',
            
            // الموقع - اختياري
            'country_id' => 'nullable|exists:countries,id',
            'state_id' => 'nullable|exists:states,id',
            'city_id' => 'nullable|exists:cities,id',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            
            // بيانات المزاد - اختياري
            'starting_price' => 'nullable|numeric|min:1|max:999999999.99',
            'min_accept_price' => 'nullable|numeric|min:0|max:999999999.99',
            
            // مدة وتوقيت المزاد - اختياري
            'starts_at' => 'nullable|date',
            'duration_days' => 'nullable|integer|min:1|max:30',
            
            // الصور - اختياري
            'images' => 'nullable|array',
            'images.*' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
            
            // السمات - اختياري
            'attributes' => 'nullable|array',
            'attributes.*.id' => 'required_with:attributes|exists:attributes,id',
            'attributes.*.value' => 'required_with:attributes',
        ];
    }

    protected function prepareForValidation(): void
    {
        // تحويل attributes من JSON لو جاي كـ string
        if ($this->has('attributes') && is_string($this->input('attributes'))) {
            $this->merge([
                'attributes' => json_decode($this->input('attributes'), true)
            ]);
        }
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Validation failed.',
            'errors' => $validator->errors()
        ], 422));
    }
}
