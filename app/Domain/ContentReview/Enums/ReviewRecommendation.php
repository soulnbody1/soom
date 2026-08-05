<?php

declare(strict_types=1);

namespace App\Domain\ContentReview\Enums;

enum ReviewRecommendation: string
{
    case Approve = 'approve';
    case Reject = 'reject';
    case NeedsHuman = 'needs_human';
}
