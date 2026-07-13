<?php

declare(strict_types=1);

namespace App\Domain\Auction\Enums;

enum WinnerDefaultDepositDisposition: string
{
    case FullForfeit = 'full_forfeit';
    case PartialForfeit = 'partial_forfeit';
    case Refund = 'refund';
    case ManualReview = 'manual_review';
    case NoAction = 'no_action';
}
