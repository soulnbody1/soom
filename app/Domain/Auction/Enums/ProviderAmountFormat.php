<?php

declare(strict_types=1);

namespace App\Domain\Auction\Enums;

enum ProviderAmountFormat: string
{
    case MinorUnits = 'minor_units';
    case DecimalString = 'decimal_string';
}
