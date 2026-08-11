<?php

declare(strict_types=1);

namespace App\DTO\ContentReview;

final readonly class AutomationContext extends BaseContentReviewDTO
{
    public function __construct(
        public bool $isAutomationEligible,
        public array $reasons,
        public array $approvalBlockers = [],
    ) {}

    public static function eligible(array $approvalBlockers = []): self
    {
        return new self(true, [], $approvalBlockers);
    }

    public static function ineligible(array $reasons, array $approvalBlockers = []): self
    {
        return new self(false, $reasons, $approvalBlockers);
    }

    public function blocksAutomaticApproval(): bool
    {
        return $this->approvalBlockers !== [];
    }

    public function toArray(): array
    {
        return [
            'is_automation_eligible' => $this->isAutomationEligible,
            'reasons' => $this->reasons,
            'approval_blockers' => $this->approvalBlockers,
        ];
    }
}
