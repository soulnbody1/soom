<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Support;

use App\Domain\ContentReview\Enums\ContentReviewDecisionType;
use App\Domain\ContentReview\Enums\DecisionActorType;
use App\Domain\ContentReview\Enums\DecisionRelation;
use App\Domain\ContentReview\Enums\ReviewableSubjectType;
use App\Models\ContentReview\ContentReview;
use App\Models\ContentReview\ContentReviewDecision;
use App\Repositories\ContentReview\ContentReviewDecisionRepository;

final class ContentReviewDecisionRecorder
{
    public function __construct(private readonly ContentReviewDecisionRepository $decisions) {}

    public function recordHumanDecision(
        ?ContentReview $review,
        ReviewableSubjectType $subjectType,
        int $subjectId,
        ContentReviewDecisionType $decision,
        DecisionRelation $relation,
        int $adminId,
        string $reason,
    ): ContentReviewDecision {
        $reason = trim($reason);

        return $this->decisions->record(
            $review === null ? null : (int) $review->id,
            $subjectType,
            $subjectId,
            $decision,
            DecisionActorType::Admin,
            $adminId,
            $relation,
            $review?->recommendation,
            $review?->confidence === null ? null : (int) $review->confidence,
            $reason === '' ? null : $reason,
        );
    }
}
