<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class PlaceBidRequest extends FormRequest
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
            'amount' => 'required|numeric|min:1|max:999999999.99',
            'deposit_transaction_id' => 'required|string|max:255',
            'terms_accepted' => 'required|accepted',
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
            'amount.required' => '⚠️ مبلغ المزايدة مطلوب.',
            'amount.numeric' => '⚠️ مبلغ المزايدة يجب أن يكون رقماً.',
            'amount.min' => '⚠️ مبلغ المزايدة يجب أن يكون أكبر من صفر.',
            'deposit_transaction_id.required' => '⚠️ معرف معاملة التأمين مطلوب.',
        ];
    }
}
