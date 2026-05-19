<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class PaymentSlipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return request()->bearerToken() !== null && auth('sanctum')->check();
    }

    public function rules(): array
    {
        return [
            'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
            'payment_method_id' => 'required|exists:payment_methods,id',
            'amount' => 'required|numeric|min:0.01|max:999999999.99',
            'payable_type' => 'required|in:auction,bid',
            'payable_id' => 'required|integer|min:1',
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

    public function messages(): array
    {
        return [
            'image.required' => '⚠️ صورة الإيصال مطلوبة.',
            'image.image' => '⚠️ الملف يجب أن يكون صورة.',
            'image.mimes' => '⚠️ الصيغ المسموحة: jpeg, png, jpg, gif, webp.',
            'image.max' => '⚠️ حجم الصورة يجب ألا يتجاوز 5 ميجا بايت.',
            'payment_method_id.required' => '⚠️ طريقة الدفع مطلوبة.',
            'payment_method_id.exists' => '⚠️ طريقة الدفع غير موجودة.',
            'amount.required' => '⚠️ المبلغ مطلوب.',
            'amount.numeric' => '⚠️ المبلغ يجب أن يكون رقم.',
            'payable_type.required' => '⚠️ نوع الدفع مطلوب.',
            'payable_type.in' => '⚠️ نوع الدفع يجب أن يكون auction أو bid.',
            'payable_id.required' => '⚠️ معرف الدفع مطلوب.',
        ];
    }
}