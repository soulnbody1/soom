<?php

declare(strict_types=1);

use App\Domain\Auction\Enums\ProviderAmountFormat;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Services\Auction\Payments\ProviderAmountFormatter;
use App\Services\Auction\Payments\ProviderCapabilities;

function capabilities(ProviderAmountFormat $format, array $currencies = ['JOD', 'USD']): ProviderCapabilities
{
    return new ProviderCapabilities($format, $currencies, true, true, true, true);
}

test('jod is formatted with three decimals and never rounded', function () {
    $formatter = new ProviderAmountFormatter;
    $decimal = capabilities(ProviderAmountFormat::DecimalString);

    expect($formatter->format(10_500, 'JOD', $decimal))->toBe('10.500')
        ->and($formatter->format(1, 'JOD', $decimal))->toBe('0.001')
        ->and($formatter->format(999, 'JOD', $decimal))->toBe('0.999')
        ->and($formatter->format(1_234_567, 'JOD', $decimal))->toBe('1234.567');
});

test('usd keeps two decimals', function () {
    $formatter = new ProviderAmountFormatter;

    expect($formatter->format(10_50, 'USD', capabilities(ProviderAmountFormat::DecimalString)))->toBe('10.50');
});

test('minor unit providers receive the exact integer', function () {
    $formatter = new ProviderAmountFormatter;

    expect($formatter->format(10_500, 'JOD', capabilities(ProviderAmountFormat::MinorUnits)))->toBe('10500');
});

test('amounts round trip back to minor units without loss', function () {
    $formatter = new ProviderAmountFormatter;
    $decimal = capabilities(ProviderAmountFormat::DecimalString);

    foreach ([1, 999, 1_000, 10_500, 1_234_567] as $minor) {
        expect($formatter->toMinor($formatter->format($minor, 'JOD', $decimal), 'JOD', $decimal))->toBe($minor);
    }
});

test('a provider amount with too much precision is refused instead of rounded', function () {
    $formatter = new ProviderAmountFormatter;

    expect(fn () => $formatter->toMinor('10.5001', 'JOD', capabilities(ProviderAmountFormat::DecimalString)))
        ->toThrow(AuctionException::class);
});

test('an unsupported provider currency is refused', function () {
    $formatter = new ProviderAmountFormatter;

    expect(fn () => $formatter->format(1_000, 'JOD', capabilities(ProviderAmountFormat::DecimalString, ['USD'])))
        ->toThrow(AuctionException::class);
});
