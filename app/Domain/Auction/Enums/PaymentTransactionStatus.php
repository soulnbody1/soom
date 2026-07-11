<?php

declare(strict_types=1);

namespace App\Domain\Auction\Enums;

enum PaymentTransactionStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Reversed = 'reversed';
}
