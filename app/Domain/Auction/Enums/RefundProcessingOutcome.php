<?php

declare(strict_types=1);

namespace App\Domain\Auction\Enums;

enum RefundProcessingOutcome: string
{
    case Succeeded = 'succeeded';
    case RetryableFailure = 'retryable_failure';
    case NonRetryableFailure = 'non_retryable_failure';
    case ManualReviewRequired = 'manual_review_required';
}
