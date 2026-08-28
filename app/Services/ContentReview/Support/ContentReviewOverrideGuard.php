<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Support;

use App\Domain\ContentReview\Enums\ContentReviewDecisionType;
use App\Domain\ContentReview\Enums\DecisionRelation;
use App\Domain\ContentReview\Enums\ReviewMode;
use App\Domain\ContentReview\Enums\ReviewRecommendation;
use App\Domain\ContentReview\Exceptions\ContentReviewException;
use App\Models\ContentReview\ContentReview;

final class ContentReviewOverrideGuard
{
    public const MAX_REASON_CHARS = 1000;

    public function relationFor(?ContentReview $review, ContentReviewDecisionType $decision): DecisionRelation
    {
        if ($review === null) {
            return DecisionRelation::None;
        }

        $recommendation = $review->recommendation;

        if ($recommendation === null) {
            return DecisionRelation::Unavailable;
        }

        $mirrored = match ($recommendation) {
            ReviewRecommendation::Approve => ContentReviewDecisionType::Approved,
            ReviewRecommendation::Reject => ContentReviewDecisionType::Rejected,
            ReviewRecommendation::NeedsHuman => null,
        };

        if ($mirrored === null) {
            return DecisionRelation::None;
        }

        return $mirrored === $decision ? DecisionRelation::Confirmed : DecisionRelation::Overridden;
    }

    public function isBinding(?ContentReview $review): bool
    {
        return $review !== null
            && $review->mode->isAtLeastAsPermissiveAs(ReviewMode::AiAssisted);
    }

    public function assertAllowed(?ContentReview $review, DecisionRelation $relation, string $reason): void
    {
        if ($relation !== DecisionRelation::Overridden || ! $this->isBinding($review)) {
            return;
        }

        if (trim($reason) === '') {
            throw ContentReviewException::domain('override_reason_required');
        }
    }
}
