<?php

declare(strict_types=1);

namespace App\Domain\Auction\Enums;

enum PaymentRail: string
{
    case Transfer = 'transfer';
    case Card = 'card';
    case Bill = 'bill';
    case Instant = 'instant';
}
