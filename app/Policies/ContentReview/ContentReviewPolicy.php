<?php

declare(strict_types=1);

namespace App\Policies\ContentReview;

use App\Models\ContentReview\ContentReview;
use App\Models\User;
use App\Policies\ContentReview\Concerns\ChecksContentReviewPermissions;

final class ContentReviewPolicy
{
    use ChecksContentReviewPermissions;

    public function viewAny(User $user): bool
    {
        return $this->hasContentReviewPermission($user, 'content_review.view');
    }

    public function view(User $user, ContentReview $review): bool
    {
        return $this->hasContentReviewPermission($user, 'content_review.view');
    }

    public function run(User $user): bool
    {
        return $this->hasContentReviewPermission($user, 'content_review.run');
    }

    public function cancel(User $user, ContentReview $review): bool
    {
        return $this->hasContentReviewPermission($user, 'content_review.cancel');
    }

    public function forceManual(User $user): bool
    {
        return $this->hasContentReviewPermission($user, 'content_review.force_manual');
    }

    public function override(User $user): bool
    {
        return $this->hasContentReviewPermission($user, 'content_review.override');
    }

    public function manageSettings(User $user): bool
    {
        return $this->hasContentReviewPermission($user, 'content_review.settings.manage');
    }

    public function managePolicy(User $user): bool
    {
        return $this->hasContentReviewPermission($user, 'content_review.policy.manage');
    }

    public function viewMetrics(User $user): bool
    {
        return $this->hasContentReviewPermission($user, 'content_review.metrics.view');
    }

    public function viewCosts(User $user): bool
    {
        return $this->hasContentReviewPermission($user, 'content_review.costs.view');
    }

    public function viewTechnical(User $user): bool
    {
        return $this->hasContentReviewPermission($user, 'content_review.technical.view');
    }
}
