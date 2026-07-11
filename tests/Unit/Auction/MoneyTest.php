<?php

declare(strict_types=1);

use App\Domain\Auction\ValueObjects\Money;

test('money parses decimal strings into minor units using currency exponent', function () {
    $money = Money::fromDecimalString('123.456', 'jod');

    expect($money->minor)->toBe(123456)
        ->and($money->currency)->toBe('JOD')
        ->and($money->toDecimalString())->toBe('123.456');
});

test('money keeps two decimal currencies at two decimals', function () {
    $money = Money::fromDecimalString('123.45', 'egp');

    expect($money->minor)->toBe(12345)
        ->and($money->toDecimalString())->toBe('123.45');
});

test('money rejects mixed currencies', function () {
    Money::fromDecimalString('10.00', 'JOD')->add(Money::fromDecimalString('1.00', 'USD'));
})->throws(InvalidArgumentException::class);

test('money accepts three decimal JOD values', function () {
    expect(Money::fromDecimalString('10.001', 'JOD')->minor)->toBe(10001);
});

test('money rejects values with more than three decimals for JOD', function () {
    Money::fromDecimalString('10.0001', 'JOD');
})->throws(InvalidArgumentException::class);

test('money rejects values with more decimals than the currency exponent', function () {
    Money::fromDecimalString('10.001', 'USD');
})->throws(InvalidArgumentException::class);
