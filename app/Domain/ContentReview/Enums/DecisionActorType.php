<?php

declare(strict_types=1);

namespace App\Domain\ContentReview\Enums;

enum DecisionActorType: string
{
    case Admin = 'admin';
    case Ai = 'ai';
    case System = 'system';

    public function carriesUserId(): bool
    {
        return $this === self::Admin;
    }
}
