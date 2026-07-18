<?php

declare(strict_types=1);

namespace App\Domain\Auction\Enums;

enum SellerPayoutStatus: string
{
    case Pending = 'pending';
    case OnHold = 'on_hold';
    case Processing = 'processing';
    case Paid = 'paid';
    case Failed = 'failed';
    case ManualReview = 'manual_review';
}
