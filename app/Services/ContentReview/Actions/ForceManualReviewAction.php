<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Actions;

use App\Domain\ContentReview\Enums\ContentReviewOutcome;
use App\Domain\ContentReview\Enums\ContentReviewStatus;
use App\Domain\ContentReview\Enums\ReviewableSubjectType;
use App\Domain\ContentReview\Exceptions\ContentReviewException;
use App\Models\ContentReview\ContentReview;
use App\Repositories\ContentReview\ContentReviewRepository;
use App\Services\ContentReview\Support\ReviewSubjectRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class ForceManualReviewAction
{
    public const REASON_CODE = 'forced_manual_review';

    public function __construct(
        private readonly ContentReviewRepository $reviews,
        private readonly ReviewSubjectRegistry $registry,
    ) {}

    public function execute(ReviewableSubjectType $type, int $subjectId, ?int $adminId): Collection
    {
        if (! $this->registry->supports($type)) {
            throw ContentReviewException::domain('subject_type_not_supported', [], 422);
        }

        if (! $this->registry->for($type)->isReviewable($subjectId)) {
            throw ContentReviewException::domain('subject_not_reviewable', [], 409);
        }

        $pending = $this->reviews->pendingForSubject($type, $subjectId);

        $stopped = $pending->map(fn (ContentReview $review): ContentReview => $this->reviews->update($review, [
            'status' => ContentReviewStatus::Cancelled->value,
            'outcome' => ContentReviewOutcome::NoDecision->value,
            'reason_code' => self::REASON_CODE,
            'requires_human_review' => true,
            'current_marker' => null,
            'lease_owner' => null,
            'leased_until' => null,
            'completed_at' => Carbon::now(),
            'decided_at' => Carbon::now(),
            'requested_by' => $review->requested_by ?? $adminId,
        ]));

        $this->reviews->deactivateAllForSubject($type, $subjectId);

        return $stopped;
    }
}
