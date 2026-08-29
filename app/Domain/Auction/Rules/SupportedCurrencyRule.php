<?php

declare(strict_types=1);

namespace App\Domain\Auction\Rules;

use App\Domain\Auction\ValueObjects\Currency;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class SupportedCurrencyRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && Currency::isSupported($value)) {
            return;
        }

        $fail(__('auction.errors.unsupported_currency', [
            'code' => is_scalar($value) ? (string) $value : gettype($value),
        ]));
    }
}
