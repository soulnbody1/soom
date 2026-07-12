<?php

declare(strict_types=1);

namespace App\Domain\Auction\Enums;

enum RefundTransactionStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case ManualReview = 'manual_review';
    case Cancelled = 'cancelled';
}
