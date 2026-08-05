<?php

declare(strict_types=1);

namespace App\Domain\ContentReview\Enums;

enum ReviewMode: string
{
    case Manual = 'manual';
    case AiAssisted = 'ai_assisted';
    case AiAutomatic = 'ai_automatic';
    case Shadow = 'shadow';

    public function callsProvider(): bool
    {
        return $this !== self::Manual;
    }

    public function allowsAutomaticDecision(): bool
    {
        return $this === self::AiAutomatic;
    }

    public function automationRank(): int
    {
        return match ($this) {
            self::Manual => 0,
            self::Shadow => 1,
            self::AiAssisted => 2,
            self::AiAutomatic => 3,
        };
    }

    public function isAtLeastAsPermissiveAs(self $other): bool
    {
        return $this->automationRank() >= $other->automationRank();
    }
}
