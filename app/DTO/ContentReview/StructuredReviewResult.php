<?php

declare(strict_types=1);

namespace App\DTO\ContentReview;

use App\Domain\ContentReview\Enums\ReviewRecommendation;
use App\Domain\ContentReview\Enums\ReviewRiskLevel;
use App\Domain\ContentReview\Enums\ViolationSeverity;

final readonly class StructuredReviewResult extends BaseContentReviewDTO
{
    public function __construct(
        public ReviewRecommendation $recommendation,
        public int $confidence,
        public ReviewRiskLevel $riskLevel,
        public bool $requiresHumanReview,
        public string $summaryAr,
        public ?string $summaryEn,
        public array $categories,
        public array $violations,
        public array $findings,
        public array $policyChecks,
        public array $missingInformation,
        public array $imageChecks = [],
    ) {}

    public function hasViolations(): bool
    {
        return $this->violations !== [];
    }

    public function hasViolationAtLeast(ViolationSeverity $severity, array $codes): bool
    {
        foreach ($this->violations as $violation) {
            $violationSeverity = ViolationSeverity::tryFrom((string) ($violation['severity'] ?? ''));

            if ($violationSeverity === null || ! $violationSeverity->isAtLeast($severity)) {
                continue;
            }

            if ($codes === [] || in_array((string) ($violation['code'] ?? ''), $codes, true)) {
                return true;
            }
        }

        return false;
    }

    public function toArray(): array
    {
        return [
            'recommendation' => $this->recommendation->value,
            'confidence' => $this->confidence,
            'risk_level' => $this->riskLevel->value,
            'requires_human_review' => $this->requiresHumanReview,
            'summary_ar' => $this->summaryAr,
            'summary_en' => $this->summaryEn,
            'categories' => $this->categories,
            'violations' => $this->violations,
            'findings' => $this->findings,
            'policy_checks' => $this->policyChecks,
            'missing_information' => $this->missingInformation,
            'image_checks' => array_values($this->imageChecks),
        ];
    }
}
