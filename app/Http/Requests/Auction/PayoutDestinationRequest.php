<?php

declare(strict_types=1);

namespace App\Http\Requests\Auction;

use App\Models\Auction\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PayoutDestinationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'recipient_name' => ['required', 'string', 'max:120'],
            'identifier_type' => ['required', 'string', Rule::in(PaymentMethod::IDENTIFIER_TYPES)],
            'identifier_value' => ['required', 'string', 'max:160'],
            'is_default' => ['nullable', 'boolean'],
        ];
    }
}
