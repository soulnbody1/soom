<?php

declare(strict_types=1);

namespace App\DTO\ContentReview;

use App\Domain\ContentReview\Enums\ContentReviewOutcome;
use App\Domain\ContentReview\Enums\ReviewRecommendation;

final readonly class ReviewDecisionDTO extends BaseContentReviewDTO
{
    public function __construct(
        public ContentReviewOutcome $outcome,
        public ?ReviewRecommendation $recommendation,
        public ?int $confidence,
        public string $reasonCode,
        public array $reasonParams = [],
    ) {}

    public function mutatesSubject(): bool
    {
        return $this->outcome->mutatesSubject();
    }

    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome->value,
            'recommendation' => $this->recommendation?->value,
            'confidence' => $this->confidence,
            'reason_code' => $this->reasonCode,
            'reason_params' => $this->reasonParams,
        ];
    }
}
