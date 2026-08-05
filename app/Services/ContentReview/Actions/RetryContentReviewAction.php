<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Actions;

use App\Domain\ContentReview\Enums\ReviewTrigger;
use App\Domain\ContentReview\Exceptions\ContentReviewException;
use App\Models\ContentReview\ContentReview;
use App\Repositories\ContentReview\ContentReviewRepository;
use App\Services\ContentReview\Support\ContentHasher;
use App\Services\ContentReview\Support\ContentReviewActionResolver;
use App\Services\ContentReview\Support\ReviewModeResolver;
use App\Services\ContentReview\Support\ReviewSubjectRegistry;

final class RetryContentReviewAction
{
    public function __construct(
        private readonly ContentReviewRepository $reviews,
        private readonly ContentReviewActionResolver $actions,
        private readonly ReviewModeResolver $modes,
        private readonly ReviewSubjectRegistry $registry,
        private readonly ContentHasher $hasher,
        private readonly RequestContentReviewAction $request,
    ) {}

    public function execute(ContentReview $review, ?int $adminId): ContentReview
    {
        if (! $this->actions->isRetryable($review)) {
            throw ContentReviewException::domain('review_not_retryable', [], 409);
        }

        $type = $review->subject_type;
        $subjectId = (int) $review->subject_id;

        if (! $this->modes->resolve($type)->callsProvider()) {
            throw ContentReviewException::domain('review_manual_mode', [], 409);
        }

        if (! $this->registry->supports($type)) {
            throw ContentReviewException::domain('subject_type_not_supported', [], 422);
        }

        $adapter = $this->registry->for($type);

        if (! $adapter->isReviewable($subjectId)) {
            throw ContentReviewException::domain('subject_not_reviewable', [], 409);
        }

        $content = $adapter->buildContent($subjectId);

        if ($content === null) {
            throw ContentReviewException::domain('content_unavailable', [], 409);
        }

        if ($this->hasher->hashContent($content) !== $review->content_hash) {
            throw ContentReviewException::domain('review_stale', [], 409);
        }

        $this->reviews->deactivate($review);

        $created = $this->request->execute($type, $subjectId, ReviewTrigger::AdminRetry, $adminId);

        if ($created === null) {
            throw ContentReviewException::domain('subject_not_reviewable', [], 409);
        }

        return $created;
    }
}
