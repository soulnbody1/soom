<?php

declare(strict_types=1);

namespace App\DTO\ContentReview;

final readonly class DeterministicCheckResult extends BaseContentReviewDTO
{
    public function __construct(
        public array $findings,
        public bool $hardFailure,
    ) {}

    public static function pass(): self
    {
        return new self([], false);
    }

    public function hasHardFailure(): bool
    {
        return $this->hardFailure;
    }

    public function failedRuleCodes(): array
    {
        return array_values(array_map(
            static fn (array $finding): string => (string) ($finding['rule_code'] ?? ''),
            array_filter($this->findings, static fn (array $finding): bool => ($finding['passed'] ?? true) === false)
        ));
    }

    public function toArray(): array
    {
        return [
            'findings' => $this->findings,
            'hard_failure' => $this->hardFailure,
        ];
    }
}
