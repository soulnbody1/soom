<?php

declare(strict_types=1);

namespace App\Http\Requests\Auction;

use App\Domain\Auction\Rules\CurrencyDecimalRule;
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
            'amount' => ['required', new CurrencyDecimalRule('currency_code')],
            'currency_code' => ['required', 'string', 'size:3', 'in:JOD,EGP,USD'],
            'idempotency_key' => ['required', 'string', 'max:120'],
            'client_request_id' => ['nullable', 'string', 'max:120'],
        ];
    }
}
