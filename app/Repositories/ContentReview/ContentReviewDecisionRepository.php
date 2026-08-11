<?php

declare(strict_types=1);

namespace App\Repositories\ContentReview;

use App\Domain\ContentReview\Enums\ContentReviewDecisionType;
use App\Domain\ContentReview\Enums\DecisionActorType;
use App\Domain\ContentReview\Enums\DecisionRelation;
use App\Domain\ContentReview\Enums\ReviewableSubjectType;
use App\Domain\ContentReview\Enums\ReviewRecommendation;
use App\Models\ContentReview\ContentReviewDecision;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class ContentReviewDecisionRepository
{
    public function record(
        ?int $reviewId,
        ReviewableSubjectType $subjectType,
        int $subjectId,
        ContentReviewDecisionType $decision,
        DecisionActorType $actorType,
        ?int $actorId,
        ?DecisionRelation $relation,
        ?ReviewRecommendation $aiRecommendation,
        ?int $aiConfidence,
        ?string $reason,
    ): ContentReviewDecision {
        return ContentReviewDecision::create([
            'review_id' => $reviewId,
            'subject_type' => $subjectType->value,
            'subject_id' => $subjectId,
            'decision' => $decision->value,
            'decided_by_type' => $actorType->value,
            'decided_by_id' => $actorType->carriesUserId() ? $actorId : null,
            'relation_to_recommendation' => $relation?->value,
            'ai_recommendation' => $aiRecommendation?->value,
            'ai_confidence' => $aiConfidence,
            'reason' => $reason,
            'decided_at' => Carbon::now(),
            'created_at' => Carbon::now(),
        ]);
    }

    public function hasHumanDecision(int $reviewId): bool
    {
        return ContentReviewDecision::where('review_id', $reviewId)
            ->where('decided_by_type', DecisionActorType::Admin->value)
            ->exists();
    }

    public function orphanCount(): int
    {
        return ContentReviewDecision::whereNull('review_id')->count();
    }

    public function forSubject(ReviewableSubjectType $subjectType, int $subjectId): Collection
    {
        return ContentReviewDecision::with('decidedBy:id,name')
            ->where('subject_type', $subjectType->value)
            ->where('subject_id', $subjectId)
            ->orderBy('decided_at')
            ->orderBy('id')
            ->get();
    }
}
