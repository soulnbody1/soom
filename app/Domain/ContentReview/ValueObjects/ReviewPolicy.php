<?php

declare(strict_types=1);

namespace App\Domain\ContentReview\ValueObjects;

use App\Domain\ContentReview\Enums\ReviewRiskLevel;

final readonly class ReviewPolicy
{
    public function __construct(
        public array $policy,
        public ?int $policyId = null,
        public ?int $policyVersion = null,
        public string $promptVersion = 'v1',
        public int $resultSchemaVersion = 1,
    ) {}

    public static function fromArray(
        array $policy,
        ?int $policyId = null,
        ?int $policyVersion = null,
        string $promptVersion = 'v1',
        int $resultSchemaVersion = 1
    ): self {
        return new self($policy, $policyId, $policyVersion, $promptVersion, $resultSchemaVersion);
    }

    public function locales(): array
    {
        return $this->stringList('locales', ['ar', 'en']);
    }

    public function analyzedTextFields(): array
    {
        return $this->stringList('analyzed_text_fields', ['title', 'description']);
    }

    public function analyzeImages(): bool
    {
        return (bool) ($this->policy['analyze_images'] ?? true);
    }

    public function maxImages(): int
    {
        return max(0, (int) ($this->policy['max_images'] ?? 4));
    }

    public function imageMaxEdgePx(): int
    {
        return max(1, (int) ($this->policy['image_max_edge_px'] ?? 1024));
    }

    public function prohibitedCategories(): array
    {
        return $this->stringList('prohibited_categories', []);
    }

    public function autoRejectCategories(): array
    {
        return $this->stringList('auto_reject_categories', []);
    }

    public function humanReviewCategories(): array
    {
        return $this->stringList('human_review_categories', []);
    }

    public function violationCodes(): array
    {
        return $this->stringList('violation_codes', []);
    }

    public function thresholds(): array
    {
        $thresholds = $this->policy['thresholds'] ?? [];

        return [
            'min_confidence_approve' => (int) ($thresholds['min_confidence_approve'] ?? 85),
            'min_confidence_reject' => (int) ($thresholds['min_confidence_reject'] ?? 90),
            'grey_zone_low' => (int) ($thresholds['grey_zone_low'] ?? 50),
            'grey_zone_high' => (int) ($thresholds['grey_zone_high'] ?? 85),
        ];
    }

    public function minConfidenceApprove(): int
    {
        return $this->thresholds()['min_confidence_approve'];
    }

    public function minConfidenceReject(): int
    {
        return $this->thresholds()['min_confidence_reject'];
    }

    public function maxRiskLevelForAutoApprove(): ReviewRiskLevel
    {
        return ReviewRiskLevel::tryFrom((string) ($this->policy['max_risk_level_for_auto_approve'] ?? 'low'))
            ?? ReviewRiskLevel::Low;
    }

    public function deterministicRules(): array
    {
        $rules = $this->policy['deterministic_rules'] ?? [];

        return is_array($rules) ? $rules : [];
    }

    public function deterministicRule(string $key, mixed $default = null): mixed
    {
        return $this->deterministicRules()[$key] ?? $default;
    }

    private function stringList(string $key, array $default): array
    {
        $value = $this->policy[$key] ?? null;

        if (! is_array($value)) {
            return $default;
        }

        return array_values(array_map(static fn ($item): string => (string) $item, $value));
    }
}
