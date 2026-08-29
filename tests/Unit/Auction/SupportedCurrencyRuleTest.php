<?php

declare(strict_types=1);

use App\Domain\Auction\Rules\SupportedCurrencyRule;
use App\Domain\Auction\ValueObjects\Currency;
use Illuminate\Support\Facades\Validator;

function validateCurrency(mixed $value): array
{
    return Validator::make(
        ['currency_code' => $value],
        ['currency_code' => [new SupportedCurrencyRule]]
    )->errors()->get('currency_code');
}

test('the rule accepts every currency the value object supports', function () {
    foreach (Currency::supportedCodes() as $code) {
        expect(validateCurrency($code))->toBe([]);
    }
});

test('the rule accepts a supported code in any case', function () {
    expect(validateCurrency('jod'))->toBe([])
        ->and(validateCurrency('Usd'))->toBe([]);
});

test('the rule rejects an unsupported code with the shared message', function () {
    expect(validateCurrency('GBP'))
        ->toBe([__('auction.errors.unsupported_currency', ['code' => 'GBP'])]);
});

test('the rule rejects non string values', function () {
    expect(validateCurrency(123))->not->toBe([])
        ->and(validateCurrency(['JOD']))->not->toBe([]);
});
