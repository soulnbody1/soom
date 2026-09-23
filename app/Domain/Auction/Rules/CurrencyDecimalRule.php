<?php

declare(strict_types=1);

namespace App\Domain\Auction\Rules;

use App\Domain\Auction\ValueObjects\Currency;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

final class CurrencyDecimalRule implements ValidationRule
{
    public function __construct(
        private readonly string $currencyField = 'currency_code',
        private readonly ?string $currency = null,
    ) {}

    public static function forCurrency(string $currency): self
    {
        return new self(currency: strtoupper($currency));
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $currencyCode = $this->currency
            ?? strtoupper((string) (request()->input($this->currencyField) ?? 'JOD'));

        try {
            $currency = Currency::fromCode($currencyCode);
        } catch (InvalidArgumentException) {
            $fail(__('auction.errors.unsupported_currency', ['code' => $currencyCode]));

            return;
        }

        $exponent = $currency->exponent();
        $amount = (string) $value;

        if (! preg_match('/^\d+(\.\d+)?$/', $amount)) {
            $fail(__('validation.numeric'));

            return;
        }

        $parts = explode('.', $amount, 2);
        $decimalLength = isset($parts[1]) ? strlen($parts[1]) : 0;

        if ($decimalLength > $exponent) {
            $fail(__('auction.errors.invalid_decimal_places', [
                'currency' => $currencyCode,
                'max' => $exponent,
            ]));
        }
    }
}
