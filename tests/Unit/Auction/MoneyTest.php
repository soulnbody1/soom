<?php

declare(strict_types=1);

use App\Domain\Auction\ValueObjects\Money;

test('money parses decimal strings into minor units', function () {
    $money = Money::fromDecimalString('123.45', 'jod');

    expect($money->minor)->toBe(12345)
        ->and($money->currency)->toBe('JOD')
        ->and($money->format())->toBe('123.45');
});

test('money rejects mixed currencies', function () {
    Money::fromDecimalString('10.00', 'JOD')->add(Money::fromDecimalString('1.00', 'USD'));
})->throws(InvalidArgumentException::class);

test('money rejects values with more than two decimals', function () {
    Money::fromDecimalString('10.001', 'JOD');
})->throws(InvalidArgumentException::class);
