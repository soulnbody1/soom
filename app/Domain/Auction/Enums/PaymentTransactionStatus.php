<?php

declare(strict_types=1);

namespace App\Domain\Auction\Enums;

enum PaymentTransactionStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Reversed = 'reversed';

    public function isTerminal(): bool
    {
        return $this !== self::Pending;
    }
}
