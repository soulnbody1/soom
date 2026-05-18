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
            'payment_method' => 'required|in:cliq,credit_card,bank_transfer,cash',
            'payment_token' => 'required|string|max:500',
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
            'payment_method.required' => '⚠️ طريقة الدفع مطلوبة.',
            'payment_method.in' => '⚠️ طريقة الدفع غير مدعومة.',
            'payment_token.required' => '⚠️ توكن الدفع مطلوب.',
        ];
    }
}
