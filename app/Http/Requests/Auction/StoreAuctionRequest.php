<?php

declare(strict_types=1);

namespace App\Http\Requests\Auction;

use App\Domain\Auction\Rules\CurrencyDecimalRule;
use App\Domain\Auction\ValueObjects\Money;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class StoreAuctionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'country_id' => ['required', 'integer', 'exists:countries,id'],
            'state_id' => ['nullable', 'integer', 'exists:states,id'],
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'title' => ['required', 'string', 'max:180'],
            'description' => ['required', 'string', 'max:10000'],
            'currency_code' => ['required', 'string', 'size:3', 'in:JOD,EGP,USD'],
            'starting_amount' => ['required', new CurrencyDecimalRule('currency_code')],
            'reserve_amount' => ['nullable', new CurrencyDecimalRule('currency_code')],
            'starts_at' => ['required', 'date', 'after:now'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'media' => ['nullable', 'array', 'max:12'],
            'media.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Skip if the underlying amount/currency fields already failed their rules.
            if ($validator->errors()->hasAny(['currency_code', 'starting_amount', 'reserve_amount'])) {
                return;
            }

            if ($this->input('reserve_amount') === null) {
                return;
            }

            $currency = (string) $this->input('currency_code');
            $starting = Money::fromDecimalString((string) $this->input('starting_amount'), $currency);
            $reserve = Money::fromDecimalString((string) $this->input('reserve_amount'), $currency);

            if ($reserve->minor < $starting->minor) {
                $validator->errors()->add(
                    'reserve_amount',
                    __('auction.validation.reserve_below_starting'),
                );
            }
        });
    }
}
