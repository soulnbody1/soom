<?php

declare(strict_types=1);

namespace App\Http\Requests\Auction;

use App\Models\Auction\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class MarkSellerPayoutPaidRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'payout_method' => ['required', 'string', 'max:60'],
            'transfer_reference' => ['required', 'string', 'max:160'],
            'note' => ['nullable', 'string', 'max:1000'],
            'proof' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf,webp', 'max:5120'],
            'recipient_name' => ['nullable', 'string', 'max:120', 'required_with:identifier_value'],
            'identifier_type' => ['nullable', 'string', Rule::in(PaymentMethod::IDENTIFIER_TYPES), 'required_with:identifier_value'],
            'identifier_value' => ['nullable', 'string', 'max:160'],
        ];
    }

    public function destinationOverride(): array
    {
        return [
            'recipient_name' => $this->validated('recipient_name'),
            'identifier_type' => $this->validated('identifier_type'),
            'identifier_value' => $this->validated('identifier_value'),
        ];
    }
}
