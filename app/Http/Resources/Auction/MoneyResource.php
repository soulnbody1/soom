<?php

declare(strict_types=1);

namespace App\Http\Resources\Auction;

use App\Domain\Auction\ValueObjects\Money;

final class MoneyResource
{
    public static function make(int $minor, string $currency): array
    {
        return Money::fromMinorUnits($minor, $currency)->toApi();
    }
}
