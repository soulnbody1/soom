<?php

declare(strict_types=1);

namespace App\Domain\ContentReview\Enums;

enum ImageCheckVerdict: string
{
    case Clean = 'clean';
    case Flagged = 'flagged';
    case NeedsHuman = 'needs_human';

    public function allowsAutomaticApproval(): bool
    {
        return $this === self::Clean;
    }
}
