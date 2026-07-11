<?php

declare(strict_types=1);

namespace App\Http\Resources\Auction;

final class MoneyResource
{
    public static function make(int $minor, string $currency): array
    {
        return [
            'amount' => number_format($minor / 100, 2, '.', ''),
            'minor' => $minor,
            'currency' => $currency,
        ];
    }
}
