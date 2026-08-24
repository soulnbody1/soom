<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Support;

final class UsdAmountConverter
{
    private const MICRO_DIGITS = 6;

    public function toMicros(?string $amount): ?int
    {
        $amount = trim((string) $amount);

        if ($amount === '' || preg_match('/^\d+(\.\d+)?$/', $amount) !== 1) {
            return null;
        }

        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        $micros = (int) $whole * (10 ** self::MICRO_DIGITS);

        if ($fraction === '') {
            return $micros;
        }

        $kept = str_pad(substr($fraction, 0, self::MICRO_DIGITS + 1), self::MICRO_DIGITS + 1, '0');

        return $micros + intdiv((int) $kept + 5, 10);
    }
}
