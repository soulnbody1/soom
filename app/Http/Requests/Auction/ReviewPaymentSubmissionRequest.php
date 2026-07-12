<?php

declare(strict_types=1);

namespace App\Http\Requests\Auction;

use Illuminate\Foundation\Http\FormRequest;

final class ReviewPaymentSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'in:approve,reject'],
            'note' => ['nullable', 'required_if:action,reject', 'string', 'max:1000'],
            'provider_transaction_id' => ['nullable', 'required_if:action,approve', 'string', 'max:160'],
            'override_deadline' => ['sometimes', 'boolean'],
            'override_reason' => ['nullable', 'required_if:override_deadline,true', 'string', 'max:1000'],
        ];
    }
}
