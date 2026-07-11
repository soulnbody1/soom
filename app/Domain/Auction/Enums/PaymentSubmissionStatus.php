<?php

declare(strict_types=1);

namespace App\Domain\Auction\Enums;

enum PaymentSubmissionStatus: string
{
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
