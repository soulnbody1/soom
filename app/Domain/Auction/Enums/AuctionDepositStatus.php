<?php

declare(strict_types=1);

namespace App\Domain\Auction\Enums;

enum AuctionDepositStatus: string
{
    case PendingSubmission = 'pending_submission';
    case PendingReview = 'pending_review';
    case Held = 'held';
    case Rejected = 'rejected';
    case RefundPending = 'refund_pending';
    case Refunded = 'refunded';
    case Forfeited = 'forfeited';
    case AppliedToSettlement = 'applied_to_settlement';
}
