<?php

declare(strict_types=1);

namespace App\DTO\ContentReview;

use App\Domain\ContentReview\Enums\ImageCheckVerdict;
use App\Domain\ContentReview\Enums\ReviewRiskLevel;

final readonly class ImageCheckResult extends BaseContentReviewDTO
{
    public function __construct(
        public string $ref,
        public string $sha256,
        public ImageCheckVerdict $verdict,
        public ?ReviewRiskLevel $riskLevel,
        public array $findings,
        public bool $fromCache,
    ) {}

    public function withRef(string $ref): self
    {
        return new self($ref, $this->sha256, $this->verdict, $this->riskLevel, $this->findings, $this->fromCache);
    }

    public function asCacheHit(): self
    {
        return new self($this->ref, $this->sha256, $this->verdict, $this->riskLevel, $this->findings, true);
    }

    public function toArray(): array
    {
        return [
            'ref' => $this->ref,
            'verdict' => $this->verdict->value,
            'risk_level' => $this->riskLevel?->value,
            'findings' => $this->findings,
            'from_cache' => $this->fromCache,
        ];
    }
}
