<?php

declare(strict_types=1);

namespace App\Domain\ContentReview\Enums;

enum ViolationSeverity: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    public function rank(): int
    {
        return match ($this) {
            self::Low => 0,
            self::Medium => 1,
            self::High => 2,
            self::Critical => 3,
        };
    }

    public function isAtLeast(self $floor): bool
    {
        return $this->rank() >= $floor->rank();
    }
}
