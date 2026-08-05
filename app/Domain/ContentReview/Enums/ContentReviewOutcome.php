<?php

declare(strict_types=1);

namespace App\Domain\ContentReview\Enums;

enum ContentReviewOutcome: string
{
    case AutoApproved = 'auto_approved';
    case AutoRejected = 'auto_rejected';
    case EscalatedToHuman = 'escalated_to_human';
    case AdvisoryOnly = 'advisory_only';
    case NoDecision = 'no_decision';

    public function mutatesSubject(): bool
    {
        return in_array($this, [self::AutoApproved, self::AutoRejected], true);
    }
}
