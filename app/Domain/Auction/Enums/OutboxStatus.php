<?php

declare(strict_types=1);

namespace App\Domain\Auction\Enums;

enum OutboxStatus: string
{
    case Pending = 'pending';
    case Published = 'published';
    case Failed = 'failed';
}
