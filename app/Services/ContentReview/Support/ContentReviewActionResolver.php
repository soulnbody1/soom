<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Support;

use App\Domain\ContentReview\Enums\ContentReviewStatus;
use App\Domain\ContentReview\Enums\DecisionActorType;
use App\Domain\ContentReview\Enums\ReviewMode;
use App\Models\ContentReview\ContentReview;
use App\Models\ContentReview\ContentReviewDecision;

final class ContentReviewActionResolver
{
    public const RUN = 'run';

    public const RETRY = 'retry';

    public const CANCEL = 'cancel';

    public const FORCE_MANUAL = 'force_manual';

    public const CONFIRM = 'confirm';

    public const OVERRIDE = 'override';

    public function __construct(private readonly ReviewModeResolver $modes) {}

    public function isActive(ContentReview $review): bool
    {
        return $review->current_marker !== null;
    }

    public function isStale(ContentReview $review): bool
    {
        return $review->isSuperseded();
    }

    public function isRetryable(ContentReview $review): bool
    {
        return $review->status === ContentReviewStatus::Failed && $this->isActive($review);
    }

    public function isCancellable(ContentReview $review): bool
    {
        return $review->status->isPending() && $review->decided_at === null;
    }

    public function hasHumanDecision(ContentReview $review): bool
    {
        if (! $review->relationLoaded('decisions')) {
            return false;
        }

        return $review->decisions->contains(
            static fn (ContentReviewDecision $decision): bool => $decision->decided_by_type === DecisionActorType::Admin
        );
    }

    public function awaitsHumanDecision(ContentReview $review): bool
    {
        return $review->status === ContentReviewStatus::Completed
            && $review->recommendation !== null
            && $review->mode->isAtLeastAsPermissiveAs(ReviewMode::AiAssisted)
            && ! $this->isStale($review)
            && ! $this->hasHumanDecision($review);
    }

    public function for(?ContentReview $review, ?ReviewMode $mode = null): array
    {
        $actions = [];

        if ($this->providerModeIsActive($review, $mode)) {
            $actions[] = self::RUN;
        }

        if ($review !== null && $this->isRetryable($review)) {
            $actions[] = self::RETRY;
        }

        if ($review !== null && $this->isCancellable($review)) {
            $actions[] = self::CANCEL;
        }

        $actions[] = self::FORCE_MANUAL;

        if ($review !== null && $this->awaitsHumanDecision($review)) {
            $actions[] = self::CONFIRM;
            $actions[] = self::OVERRIDE;
        }

        return $actions;
    }

    private function providerModeIsActive(?ContentReview $review, ?ReviewMode $mode): bool
    {
        if ($review === null) {
            return false;
        }

        return ($mode ?? $this->modes->resolve($review->subject_type))->callsProvider();
    }
}
