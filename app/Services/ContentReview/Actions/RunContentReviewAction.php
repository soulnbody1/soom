<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Actions;

use App\Domain\ContentReview\Enums\ReviewableSubjectType;
use App\Domain\ContentReview\Enums\ReviewTrigger;
use App\Domain\ContentReview\Exceptions\ContentReviewException;
use App\Models\ContentReview\ContentReview;
use App\Services\ContentReview\Support\ReviewModeResolver;
use App\Services\ContentReview\Support\ReviewSubjectRegistry;

final class RunContentReviewAction
{
    public function __construct(
        private readonly ReviewModeResolver $modes,
        private readonly ReviewSubjectRegistry $registry,
        private readonly RequestContentReviewAction $request,
    ) {}

    public function execute(ReviewableSubjectType $type, int $subjectId, ?int $adminId): ContentReview
    {
        if (! $this->modes->resolve($type)->callsProvider()) {
            throw ContentReviewException::domain('review_manual_mode', [], 409);
        }

        if (! $this->registry->supports($type)) {
            throw ContentReviewException::domain('subject_type_not_supported', [], 422);
        }

        if (! $this->registry->for($type)->isReviewable($subjectId)) {
            throw ContentReviewException::domain('subject_not_reviewable', [], 409);
        }

        $review = $this->request->execute($type, $subjectId, ReviewTrigger::AdminManual, $adminId);

        if ($review === null) {
            throw ContentReviewException::domain('subject_not_reviewable', [], 409);
        }

        return $review;
    }
}
