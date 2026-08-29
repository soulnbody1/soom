<?php

declare(strict_types=1);

namespace App\Domain\Auction\Enums;

enum PaymentRecordStatus: string
{
    case PendingReview = 'pending_review';
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Reversed = 'reversed';

    public static function fromTransaction(PaymentTransactionStatus $status): self
    {
        return match ($status) {
            PaymentTransactionStatus::Pending => self::Pending,
            PaymentTransactionStatus::Succeeded => self::Succeeded,
            PaymentTransactionStatus::Failed => self::Failed,
            PaymentTransactionStatus::Cancelled => self::Cancelled,
            PaymentTransactionStatus::Expired => self::Expired,
            PaymentTransactionStatus::Reversed => self::Reversed,
        };
    }

    public static function fromSubmission(PaymentSubmissionStatus $status): self
    {
        return match ($status) {
            PaymentSubmissionStatus::PendingReview => self::PendingReview,
            PaymentSubmissionStatus::Rejected => self::Rejected,
            PaymentSubmissionStatus::Approved => self::Succeeded,
        };
    }

    public function transactionStatus(): ?PaymentTransactionStatus
    {
        return match ($this) {
            self::Pending => PaymentTransactionStatus::Pending,
            self::Succeeded => PaymentTransactionStatus::Succeeded,
            self::Failed => PaymentTransactionStatus::Failed,
            self::Cancelled => PaymentTransactionStatus::Cancelled,
            self::Expired => PaymentTransactionStatus::Expired,
            self::Reversed => PaymentTransactionStatus::Reversed,
            self::PendingReview, self::Rejected => null,
        };
    }

    public function submissionStatus(): ?PaymentSubmissionStatus
    {
        return match ($this) {
            self::PendingReview => PaymentSubmissionStatus::PendingReview,
            self::Rejected => PaymentSubmissionStatus::Rejected,
            default => null,
        };
    }
}
