<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class PayAuctionDepositRequest extends FormRequest
{
    public function authorize(): bool
    {
        // التأكد من وجود Token (Bearer) مش Session بس
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
            'payment_method_id' => 'required|exists:payment_methods,id',
            'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
            'amount' => 'nullable|numeric|min:0',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Validation failed.',
            'errors' => $validator->errors()
        ], 422));
    }

    public function wantsJson()
    {
        return true;
    }

    public function messages(): array
    {
        return [
            'payment_method_id.required' => '⚠️ طريقة الدفع مطلوبة.',
            'payment_method_id.exists' => '⚠️ طريقة الدفع غير موجودة.',
            'image.required' => '⚠️ صورة إيصال الدفع مطلوبة.',
            'image.image' => '⚠️ الملف يجب أن يكون صورة.',
            'image.mimes' => '⚠️ الصيغ المسموحة: jpeg, png, jpg, gif, webp.',
            'image.max' => '⚠️ حجم الصورة يجب ألا يتجاوز 5 ميجا بايت.',
        ];
    }
}
