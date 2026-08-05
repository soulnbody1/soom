<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Support;

use App\Domain\ContentReview\Enums\ContentReviewStatus;
use App\Domain\ContentReview\Enums\ReviewMode;
use App\Models\ContentReview\ContentReview;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class ContentReviewActionResolver
{
    public const RUN = 'run';

    public const RETRY = 'retry';

    public const CANCEL = 'cancel';

    public const FORCE_MANUAL = 'force_manual';

    public const OVERRIDE = 'override';

    public function __construct(private readonly ReviewModeResolver $modes) {}

    public function isActive(ContentReview $review): bool
    {
        return $review->current_marker !== null;
    }

    public function isStale(ContentReview $review): bool
    {
        return $review->current_marker === null
            || $review->superseded_at !== null
            || $review->status === ContentReviewStatus::Superseded;
    }

    public function isRetryable(ContentReview $review): bool
    {
        return $review->status === ContentReviewStatus::Failed && $this->isActive($review);
    }

    public function isCancellable(ContentReview $review): bool
    {
        return $review->status->isPending() && $review->decided_at === null;
    }

    /**
     * @return array<int, string>
     */
    public function for(?User $user, ?ContentReview $review, ?ReviewMode $mode = null): array
    {
        if ($user === null) {
            return [];
        }

        $gate = Gate::forUser($user);
        $actions = [];

        if ($gate->allows('run', ContentReview::class) && $this->providerModeIsActive($review, $mode)) {
            $actions[] = self::RUN;
        }

        if ($review !== null && $gate->allows('run', ContentReview::class) && $this->isRetryable($review)) {
            $actions[] = self::RETRY;
        }

        if ($review !== null && $this->isCancellable($review) && $gate->allows('cancel', $review)) {
            $actions[] = self::CANCEL;
        }

        if ($gate->allows('forceManual', ContentReview::class)) {
            $actions[] = self::FORCE_MANUAL;
        }

        if ($gate->allows('override', ContentReview::class)) {
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
