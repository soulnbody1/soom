<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Actions;

use App\Domain\ContentReview\Enums\ContentReviewDecisionType;
use App\Domain\ContentReview\Enums\ContentReviewErrorCode;
use App\Domain\ContentReview\Enums\ContentReviewOutcome;
use App\Domain\ContentReview\Enums\ContentReviewStatus;
use App\Domain\ContentReview\Enums\DecisionActorType;
use App\Domain\ContentReview\Enums\DecisionRelation;
use App\Domain\ContentReview\Enums\ReviewMode;
use App\DTO\ContentReview\ReviewDecisionDTO;
use App\Models\ContentReview\ContentReview;
use App\Repositories\ContentReview\ContentReviewDecisionRepository;
use App\Repositories\ContentReview\ContentReviewRepository;
use App\Services\ContentReview\Contracts\ContentReviewEventPublisher;
use App\Services\ContentReview\Support\ContentHasher;
use App\Services\ContentReview\Support\ReviewModeResolver;
use App\Services\ContentReview\Support\ReviewSubjectRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class ApplyContentReviewDecisionAction
{
    public function __construct(
        private readonly ContentReviewRepository $reviews,
        private readonly ContentReviewDecisionRepository $decisions,
        private readonly ReviewSubjectRegistry $registry,
        private readonly ReviewModeResolver $modes,
        private readonly ContentHasher $hasher,
        private readonly ContentReviewEventPublisher $events,
    ) {}

    public function execute(ContentReview $review, ReviewDecisionDTO $decision, string $reason): ContentReview
    {
        return DB::transaction(function () use ($review, $decision, $reason): ContentReview {
            $locked = $this->reviews->lockByPublicId((string) $review->public_id);

            if ($locked === null) {
                return $review;
            }

            if ($locked->decided_at !== null) {
                return $locked;
            }

            if ($locked->current_marker === null) {
                return $this->settle($locked, ContentReviewOutcome::NoDecision, 'superseded_before_apply', null);
            }

            $type = $locked->subject_type;
            $subjectId = (int) $locked->subject_id;
            $adapter = $this->registry->for($type);

            $content = $adapter->buildContent($subjectId);

            if ($content === null) {
                return $this->supersede($locked, ContentReviewErrorCode::ContentUnavailable, 'content_unavailable');
            }

            if ($this->hasher->hashContent($content) !== $locked->content_hash) {
                return $this->supersede($locked, ContentReviewErrorCode::ContentChanged, 'content_changed');
            }

            if (! $adapter->isReviewable($subjectId)) {
                return $this->supersede($locked, ContentReviewErrorCode::SubjectNotReviewable, 'subject_not_reviewable');
            }

            $frozenMode = $locked->mode;
            $currentMode = $this->modes->resolve($type);

            if ($decision->outcome->mutatesSubject() && ! $currentMode->isAtLeastAsPermissiveAs($frozenMode)) {
                return $this->settle($locked, ContentReviewOutcome::NoDecision, 'mode_became_more_restrictive', null);
            }

            if ($decision->outcome->mutatesSubject() && $currentMode !== ReviewMode::AiAutomatic) {
                return $this->settle($locked, ContentReviewOutcome::NoDecision, 'mode_does_not_apply_decisions', null);
            }

            if (! $decision->outcome->mutatesSubject()) {
                $applied = $this->settle($locked, $decision->outcome, $decision->reasonCode, null);
                $this->recordDecision($applied, $decision, $reason, false);

                return $applied;
            }

            if (! $adapter->applyDecision($subjectId, $decision->outcome, $reason)) {
                return $this->supersede($locked, ContentReviewErrorCode::SubjectNotReviewable, 'subject_not_reviewable');
            }

            $applied = $this->settle($locked, $decision->outcome, $decision->reasonCode, null);
            $this->recordDecision($applied, $decision, $reason, true);

            return $applied;
        }, 1);
    }

    private function recordDecision(ContentReview $review, ReviewDecisionDTO $decision, string $reason, bool $mutated): void
    {
        $this->decisions->record(
            (int) $review->id,
            $review->subject_type,
            (int) $review->subject_id,
            $this->decisionType($decision->outcome),
            DecisionActorType::Ai,
            null,
            DecisionRelation::None,
            $decision->recommendation,
            $decision->confidence,
            $mutated ? $reason : null,
        );
    }

    private function decisionType(ContentReviewOutcome $outcome): ContentReviewDecisionType
    {
        return match ($outcome) {
            ContentReviewOutcome::AutoApproved => ContentReviewDecisionType::Approved,
            ContentReviewOutcome::AutoRejected => ContentReviewDecisionType::Rejected,
            ContentReviewOutcome::EscalatedToHuman => ContentReviewDecisionType::Escalated,
            default => ContentReviewDecisionType::Recommended,
        };
    }

    private function settle(
        ContentReview $review,
        ContentReviewOutcome $outcome,
        string $reasonCode,
        ?ContentReviewErrorCode $errorCode,
    ): ContentReview {
        $attributes = [
            'outcome' => $outcome->value,
            'reason_code' => $reasonCode,
            'requires_human_review' => ! $outcome->mutatesSubject(),
            'decided_at' => Carbon::now(),
            'lease_owner' => null,
            'leased_until' => null,
        ];

        if ($errorCode !== null) {
            $attributes['error_code'] = $errorCode->value;
        }

        $settled = $this->reviews->update($review, $attributes);

        $this->announce($settled, $outcome);

        return $settled;
    }

    private function announce(ContentReview $review, ContentReviewOutcome $outcome): void
    {
        $eventType = match ($outcome) {
            ContentReviewOutcome::EscalatedToHuman => 'content_review.escalated',
            ContentReviewOutcome::AutoApproved, ContentReviewOutcome::AutoRejected => 'content_review.auto_decided',
            default => null,
        };

        if ($eventType !== null) {
            $this->events->publish($review, $eventType);
        }
    }

    private function supersede(ContentReview $review, ContentReviewErrorCode $code, string $reasonCode): ContentReview
    {
        return $this->reviews->update($review, [
            'status' => ContentReviewStatus::Superseded->value,
            'outcome' => ContentReviewOutcome::NoDecision->value,
            'reason_code' => $reasonCode,
            'error_code' => $code->value,
            'requires_human_review' => true,
            'current_marker' => null,
            'superseded_at' => Carbon::now(),
            'decided_at' => Carbon::now(),
            'lease_owner' => null,
            'leased_until' => null,
        ]);
    }
}
