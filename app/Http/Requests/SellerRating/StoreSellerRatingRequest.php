<?php

declare(strict_types=1);

namespace App\Http\Requests\SellerRating;

use Illuminate\Foundation\Http\FormRequest;

final class StoreSellerRatingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('message'))) {
            $this->merge(['message' => trim($this->input('message'))]);
        }
    }

    public function rules(): array
    {
        return [
            'rating' => ['required', 'integer', 'between:1,5'],
            'message' => ['required', 'string', 'max:'.config('seller_ratings.message_max_length')],
        ];
    }

    public function messages(): array
    {
        return [
            'rating.required' => 'درجة التقييم مطلوبة.',
            'rating.integer' => 'درجة التقييم يجب أن تكون رقماً صحيحاً.',
            'rating.between' => 'درجة التقييم يجب أن تكون بين 1 و5.',
            'message.required' => 'رسالة التقييم مطلوبة.',
            'message.string' => 'رسالة التقييم يجب أن تكون نصاً.',
            'message.max' => 'رسالة التقييم أطول من الحد المسموح.',
        ];
    }
}
