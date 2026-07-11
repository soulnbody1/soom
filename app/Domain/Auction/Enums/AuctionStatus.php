<?php

declare(strict_types=1);

namespace App\Domain\Auction\Enums;

enum AuctionStatus: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Rejected = 'rejected';
    case AwaitingSellerDeposit = 'awaiting_seller_deposit';
    case Scheduled = 'scheduled';
    case Live = 'live';
    case Ended = 'ended';
    case SettlementPending = 'settlement_pending';
    case PaymentPending = 'payment_pending';
    case HandoverPending = 'handover_pending';
    case Completed = 'completed';
    case Unsold = 'unsold';
    case Cancelled = 'cancelled';
    case Defaulted = 'defaulted';
    case Disputed = 'disputed';

    public function isPubliclyVisible(): bool
    {
        return in_array($this, [
            self::Scheduled,
            self::Live,
            self::Ended,
            self::SettlementPending,
            self::PaymentPending,
            self::HandoverPending,
            self::Completed,
            self::Unsold,
        ], true);
    }

    public function acceptsBids(): bool
    {
        return $this === self::Live;
    }
}
