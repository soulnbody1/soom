<?php

declare(strict_types=1);

namespace App\Domain\ContentReview\Enums;

enum ContentReviewStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Superseded = 'superseded';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Cancelled, self::Superseded], true);
    }

    public function isPending(): bool
    {
        return in_array($this, [self::Queued, self::Running], true);
    }
}
