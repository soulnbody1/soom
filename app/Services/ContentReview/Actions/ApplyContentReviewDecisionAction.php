<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Actions;

use App\Domain\ContentReview\Enums\ContentReviewDecisionType;
use App\Domain\ContentReview\Enums\ContentReviewErrorCode;
use App\Domain\ContentReview\Enums\ContentReviewOutcome;
use App\Domain\ContentReview\Enums\ContentReviewStatus;
use App\Domain\ContentReview\Enums\DecisionActorType;
use App\Domain\ContentReview\Enums\DecisionRelation;
use App\Domain\ContentReview\Enums\ReviewableSubjectType;
use App\Domain\ContentReview\Enums\ReviewMode;
use App\Domain\ContentReview\Exceptions\ContentReviewException;
use App\DTO\ContentReview\HumanDecisionResult;
use App\DTO\ContentReview\ReviewDecisionDTO;
use App\Models\ContentReview\ContentReview;
use App\Models\User;
use App\Repositories\ContentReview\ContentReviewDecisionRepository;
use App\Repositories\ContentReview\ContentReviewRepository;
use App\Services\ContentReview\Contracts\ContentReviewEventPublisher;
use App\Services\ContentReview\Support\ContentHasher;
use App\Services\ContentReview\Support\ContentReviewDecisionRecorder;
use App\Services\ContentReview\Support\ContentReviewOverrideGuard;
use App\Services\ContentReview\Support\ReviewModeResolver;
use App\Services\ContentReview\Support\ReviewSubjectRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class ApplyContentReviewDecisionAction
{
    public const EVENT_CONFIRMED = 'content_review.confirmed';

    public const EVENT_OVERRIDDEN = 'content_review.overridden';

    public function __construct(
        private readonly ContentReviewRepository $reviews,
        private readonly ContentReviewDecisionRepository $decisions,
        private readonly ReviewSubjectRegistry $registry,
        private readonly ReviewModeResolver $modes,
        private readonly ContentHasher $hasher,
        private readonly ContentReviewEventPublisher $events,
        private readonly ContentReviewOverrideGuard $overrides,
        private readonly ContentReviewDecisionRecorder $recorder,
    ) {}

    public function applyHumanDecision(
        ReviewableSubjectType $type,
        int $subjectId,
        ContentReviewDecisionType $decision,
        User $actor,
        string $reason,
        ?string $reviewPublicId = null,
    ): HumanDecisionResult {
        if (! $this->registry->supports($type)) {
            throw ContentReviewException::domain('subject_type_not_supported');
        }

        $adapter = $this->registry->for($type);
        $bound = $reviewPublicId !== null;

        if (! $adapter->allowsHumanDecision($actor, $subjectId, $decision)) {
            throw ContentReviewException::domain('decision_not_permitted', [], 403);
        }

        return DB::transaction(function () use (
            $type,
            $subjectId,
            $decision,
            $actor,
            $reason,
            $reviewPublicId,
            $bound,
            $adapter
        ): HumanDecisionResult {
            $review = $reviewPublicId === null
                ? $this->reviews->lockActiveForSubject($type, $subjectId)
                : $this->reviews->lockByPublicId($reviewPublicId);

            if ($bound && ($review === null
                || $review->subject_type !== $type
                || (int) $review->subject_id !== $subjectId)) {
                throw ContentReviewException::domain('review_not_found', [], 404);
            }

            $blocker = $review === null ? null : $this->humanDecisionBlocker($review, $subjectId);

            if ($blocker === 'review_already_decided') {
                throw ContentReviewException::domain($blocker, [], 409);
            }

            if ($blocker !== null && $bound) {
                throw ContentReviewException::domain($blocker, [], 409);
            }

            if ($blocker !== null) {
                $review = null;
            }

            if ($bound && ! $this->overrides->isBinding($review)) {
                throw ContentReviewException::domain('review_not_assisted');
            }

            $relation = $this->overrides->relationFor($review, $decision);

            if ($bound && ! in_array($relation, [DecisionRelation::Confirmed, DecisionRelation::Overridden], true)) {
                throw ContentReviewException::domain('recommendation_missing');
            }

            $this->overrides->assertAllowed($review, $relation, $reason);

            if (! $adapter->applyHumanDecision($subjectId, $decision, (int) $actor->id, $this->humanReason($review, $reason))) {
                throw ContentReviewException::domain('subject_not_reviewable', [], 409);
            }

            if ($review === null) {
                return new HumanDecisionResult($decision, DecisionRelation::None);
            }

            $record = $this->recorder->recordHumanDecision(
                $review,
                $type,
                $subjectId,
                $decision,
                $relation,
                (int) $actor->id,
                $reason,
            );

            $this->events->publish(
                $review,
                $relation === DecisionRelation::Overridden ? self::EVENT_OVERRIDDEN : self::EVENT_CONFIRMED,
                [
                    'decision' => $decision->value,
                    'relation_to_recommendation' => $relation->value,
                    'recommendation' => $review->recommendation?->value,
                    'confidence' => $review->confidence === null ? null : (int) $review->confidence,
                    'decided_by_id' => (int) $actor->id,
                    'decision_id' => (string) $record->public_id,
                ],
            );

            return new HumanDecisionResult($decision, $relation, $review, $record);
        }, 3);
    }

    private function humanDecisionBlocker(ContentReview $review, int $subjectId): ?string
    {
        if ($review->current_marker === null
            || $review->superseded_at !== null
            || $review->status === ContentReviewStatus::Superseded
            || $review->status === ContentReviewStatus::Cancelled) {
            return 'review_superseded';
        }

        if ($review->status !== ContentReviewStatus::Completed) {
            return 'review_not_ready';
        }

        if ($this->decisions->hasHumanDecision((int) $review->id)) {
            return 'review_already_decided';
        }

        $content = $this->registry->for($review->subject_type)->buildContent($subjectId);

        if ($content === null || $this->hasher->hashContent($content) !== $review->content_hash) {
            return 'review_stale';
        }

        return null;
    }

    private function humanReason(?ContentReview $review, string $reason): string
    {
        $reason = trim($reason);

        if ($reason !== '' || $review === null) {
            return $reason;
        }

        $summary = trim((string) $review->summary_ar);

        if ($summary !== '') {
            return $summary;
        }

        $recommendation = $review->recommendation;

        return $recommendation === null
            ? (string) __('content_review.outcomes.advisory_only')
            : (string) __('content_review.recommendations.'.$recommendation->value);
    }

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
