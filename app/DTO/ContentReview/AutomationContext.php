<?php

declare(strict_types=1);

namespace App\DTO\ContentReview;

final readonly class AutomationContext extends BaseContentReviewDTO
{
    public function __construct(
        public bool $isAutomationEligible,
        public array $reasons,
    ) {}

    public static function eligible(): self
    {
        return new self(true, []);
    }

    public static function ineligible(array $reasons): self
    {
        return new self(false, $reasons);
    }

    public function toArray(): array
    {
        return [
            'is_automation_eligible' => $this->isAutomationEligible,
            'reasons' => $this->reasons,
        ];
    }
}
