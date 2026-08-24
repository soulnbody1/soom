<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Support;

use App\Domain\ContentReview\Enums\ContentReviewStatus;
use App\Domain\ContentReview\Enums\ReviewMode;
use App\DTO\ContentReview\AutomationContext;
use App\DTO\ContentReview\ImageReviewSummary;
use App\Models\ContentReview\ContentReview;

final class AutomationEligibilityResolver
{
    public function __construct(
        private readonly ReviewModeResolver $modes,
        private readonly ReviewSubjectRegistry $registry,
        private readonly ContentHasher $hasher,
        private readonly ContentReviewCircuitBreaker $breaker,
        private readonly ContentReviewBudgetGuard $budget,
        private readonly ProviderSelectionResolver $selection,
    ) {}

    public function resolve(ContentReview $review): AutomationContext
    {
        $reasons = array_merge(
            $this->platformReasons($review),
            $this->reviewReasons($review),
            $this->imageReasons($review),
            $this->serviceReasons($review),
        );

        $reasons = array_merge($reasons, $this->subjectReasons($review, $reasons));

        $reasons = array_values(array_unique($reasons));
        $approvalBlockers = $this->imageSummary($review)->approvalBlockingReasons();

        return $reasons === []
            ? AutomationContext::eligible($approvalBlockers)
            : AutomationContext::ineligible($reasons, $approvalBlockers);
    }

    private function platformReasons(ContentReview $review): array
    {
        if (config('content_review.enabled') !== true) {
            return ['content_review_disabled'];
        }

        $reasons = [];

        if ($review->mode !== ReviewMode::AiAutomatic) {
            $reasons[] = 'frozen_mode_not_automatic';
        }

        if ($this->modes->resolve($review->subject_type) !== ReviewMode::AiAutomatic) {
            $reasons[] = 'mode_not_automatic';
        }

        if (! $this->registry->supports($review->subject_type)) {
            $reasons[] = 'subject_type_not_supported';
        }

        return $reasons;
    }

    private function reviewReasons(ContentReview $review): array
    {
        $reasons = [];

        if ($review->current_marker === null
            || $review->superseded_at !== null
            || $review->status === ContentReviewStatus::Superseded
            || $review->status === ContentReviewStatus::Cancelled) {
            $reasons[] = 'review_not_current';
        }

        if ($review->status !== ContentReviewStatus::Completed) {
            $reasons[] = 'review_not_completed';
        }

        if ($review->recommendation === null) {
            $reasons[] = 'recommendation_missing';
        }

        if ($this->hasBlockingDeterministicFinding($review)) {
            $reasons[] = 'deterministic_hard_failure';
        }

        return $reasons;
    }

    private function subjectReasons(ContentReview $review, array $reasons): array
    {
        if (in_array('subject_type_not_supported', $reasons, true)) {
            return [];
        }

        $adapter = $this->registry->for($review->subject_type);
        $subjectId = (int) $review->subject_id;

        if (! $adapter->isReviewable($subjectId)) {
            return ['subject_not_reviewable'];
        }

        $content = $adapter->buildContent($subjectId);

        if ($content === null) {
            return ['content_unavailable'];
        }

        if ($this->hasher->hashContent($content) !== $review->content_hash) {
            return ['review_stale'];
        }

        return $adapter->automationContext($subjectId)->reasons;
    }

    private function imageReasons(ContentReview $review): array
    {
        return $this->imageSummary($review)->blockingReasons();
    }

    private function imageSummary(ContentReview $review): ImageReviewSummary
    {
        return ImageReviewSummary::fromArray($review->image_review);
    }

    private function serviceReasons(ContentReview $review): array
    {
        $reasons = [];

        if ($this->breaker->isOpen()) {
            $reasons[] = 'provider_circuit_open';
        }

        $settings = $this->modes->effectiveSettings($review->subject_type);
        $provider = (string) ($review->provider ?? $this->selection->provider($settings));
        $model = (string) ($review->model ?? $this->selection->model($settings));
        $maxOutputTokens = max(256, (int) ($settings['max_output_tokens'] ?? 2000));

        if ($model !== '' && $this->budget->exhaustedPeriod($settings, $provider, $model, $maxOutputTokens) !== null) {
            $reasons[] = 'budget_exhausted';
        }

        return $reasons;
    }

    private function hasBlockingDeterministicFinding(ContentReview $review): bool
    {
        $findings = $review->deterministic_findings;

        if (! is_array($findings)) {
            return false;
        }

        foreach ($findings as $finding) {
            if (! is_array($finding)) {
                continue;
            }

            if (($finding['hard'] ?? false) === true && ($finding['passed'] ?? true) === false) {
                return true;
            }
        }

        return false;
    }
}
