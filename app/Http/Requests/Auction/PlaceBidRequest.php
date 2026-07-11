<?php

declare(strict_types=1);

namespace App\Http\Requests\Auction;

use Illuminate\Foundation\Http\FormRequest;

final class PlaceBidRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'regex:/^\d+(\.\d{1,2})?$/'],
            'currency_code' => ['required', 'string', 'size:3'],
            'idempotency_key' => ['required', 'string', 'max:120'],
            'client_request_id' => ['nullable', 'string', 'max:120'],
        ];
    }
}
