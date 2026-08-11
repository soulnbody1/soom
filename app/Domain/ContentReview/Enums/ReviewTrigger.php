<?php

declare(strict_types=1);

namespace App\Domain\ContentReview\Enums;

enum ReviewTrigger: string
{
    case SubmittedForReview = 'submitted_for_review';
    case AdminManual = 'admin_manual';
    case AdminRetry = 'admin_retry';
    case Sweeper = 'sweeper';
    case Backfill = 'backfill';
}
