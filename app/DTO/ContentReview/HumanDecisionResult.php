<?php

declare(strict_types=1);

namespace App\DTO\ContentReview;

use App\Domain\ContentReview\Enums\ContentReviewDecisionType;
use App\Domain\ContentReview\Enums\DecisionRelation;
use App\Models\ContentReview\ContentReview;
use App\Models\ContentReview\ContentReviewDecision;

final readonly class HumanDecisionResult extends BaseContentReviewDTO
{
    public function __construct(
        public ContentReviewDecisionType $decision,
        public DecisionRelation $relation,
        public ?ContentReview $review = null,
        public ?ContentReviewDecision $record = null,
    ) {}

    public function confirmedRecommendation(): bool
    {
        return $this->relation === DecisionRelation::Confirmed;
    }

    public function overrodeRecommendation(): bool
    {
        return $this->relation === DecisionRelation::Overridden;
    }

    public function toArray(): array
    {
        return [
            'decision' => $this->decision->value,
            'relation_to_recommendation' => $this->relation->value,
            'review_id' => $this->review === null ? null : (string) $this->review->public_id,
            'decision_id' => $this->record === null ? null : (string) $this->record->public_id,
        ];
    }
}
