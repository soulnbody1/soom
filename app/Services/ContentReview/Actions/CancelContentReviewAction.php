<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Actions;

use App\Domain\ContentReview\Enums\ContentReviewStatus;
use App\Domain\ContentReview\Exceptions\ContentReviewException;
use App\Models\ContentReview\ContentReview;
use App\Repositories\ContentReview\ContentReviewRepository;
use Illuminate\Support\Carbon;

final class CancelContentReviewAction
{
    public function __construct(private readonly ContentReviewRepository $reviews) {}

    public function execute(ContentReview $review, ?int $actorId): ContentReview
    {
        if ($review->decided_at !== null) {
            throw ContentReviewException::domain('review_already_decided', [], 409);
        }

        if (! $review->status->isPending()) {
            throw ContentReviewException::domain('review_not_cancellable', [], 409);
        }

        return $this->reviews->update($review, [
            'status' => ContentReviewStatus::Cancelled->value,
            'current_marker' => null,
            'lease_owner' => null,
            'leased_until' => null,
            'completed_at' => Carbon::now(),
            'requested_by' => $review->requested_by ?? $actorId,
        ]);
    }
}
