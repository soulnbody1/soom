<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class AuctionsConfigurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'deposit_type' => 'required|in:fixed,percentage',
            'amount' => 'required|numeric|min:0',
            'category_id' => 'nullable|exists:categories,id',
            'duration_days' => 'required|integer|min:1|max:365',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
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
            'deposit_type.required' => '⚠️ نوع التأمين مطلوب.',
            'deposit_type.in' => '⚠️ نوع التأمين يجب أن يكون fixed أو percentage.',
            'amount.required' => '⚠️ قيمة التأمين مطلوبة.',
            'amount.numeric' => '⚠️ قيمة التأمين يجب أن تكون رقم.',
            'duration_days.required' => '⚠️ عدد الأيام مطلوب.',
            'end_date.after_or_equal' => '⚠️ تاريخ النهاية يجب أن يكون بعد أو يساوي تاريخ البداية.',
        ];
    }
}