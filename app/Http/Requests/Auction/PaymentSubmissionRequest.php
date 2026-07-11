<?php

declare(strict_types=1);

namespace App\Http\Requests\Auction;

use Illuminate\Foundation\Http\FormRequest;

final class PaymentSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'payment_method_id' => ['required', 'string', 'size:26', 'exists:payment_methods,public_id'],
            'receipt' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf,webp', 'max:5120'],
            'idempotency_key' => ['required', 'string', 'max:120'],
            'provider_reference' => ['nullable', 'string', 'max:160'],
        ];
    }
}
