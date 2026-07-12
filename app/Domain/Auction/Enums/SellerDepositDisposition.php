<?php

declare(strict_types=1);

namespace App\Domain\Auction\Enums;

enum SellerDepositDisposition: string
{
    case Refund = 'refund';
    case Forfeit = 'forfeit';
    case PartialForfeit = 'partial_forfeit';
    case KeepHeld = 'keep_held';
    case ManualReview = 'manual_review';
    case NoAction = 'no_action';
    case CloseWithoutRefund = 'close_without_refund';
}
