<?php

declare(strict_types=1);

namespace App\Domain\Auction\Enums;

enum RefundTransactionStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
