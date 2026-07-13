<?php

declare(strict_types=1);

namespace App\Domain\Auction\Enums;

enum OutboxStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Processed = 'processed';
    case Failed = 'failed';
}
