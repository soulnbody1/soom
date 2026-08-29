<?php

declare(strict_types=1);

namespace App\Domain\Auction\Enums;

enum PaymentChannel: string
{
    case Manual = 'manual';
    case Online = 'online';
}
