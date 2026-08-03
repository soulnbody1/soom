<?php

declare(strict_types=1);

namespace App\Domain\Auction\Enums;

use App\Models\Auction\AuctionDeposit;

enum ParticipationDepositStatus: string
{
    case NotRequired = 'not_required';
    case NotSubmitted = 'not_submitted';
    case PendingSubmission = 'pending_submission';
    case PendingReview = 'pending_review';
    case Held = 'held';
    case Rejected = 'rejected';
    case RefundPending = 'refund_pending';
    case Refunded = 'refunded';
    case Forfeited = 'forfeited';
    case AppliedToSettlement = 'applied_to_settlement';

    public static function resolve(?AuctionDeposit $deposit, int $requiredMinor): self
    {
        if ($requiredMinor <= 0) {
            return self::NotRequired;
        }

        if (! $deposit) {
            return self::NotSubmitted;
        }

        return self::from($deposit->status->value);
    }
}
