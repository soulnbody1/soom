<?php

declare(strict_types=1);

namespace App\Domain\ContentReview\Enums;

enum ContentReviewDecisionType: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Escalated = 'escalated';
    case Recommended = 'recommended';
}
