<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Actions;

use App\Domain\ContentReview\Enums\ContentReviewErrorCode;
use App\Domain\ContentReview\ValueObjects\ReviewPolicy;
use App\DTO\ContentReview\DeterministicCheckResult;
use App\DTO\ContentReview\StructuredReviewResult;
use App\Models\ContentReview\ContentReview;
use App\Services\ContentReview\Support\AutomationEligibilityResolver;
use App\Services\ContentReview\Support\ContentReviewDecisionEngine;
use App\Services\ContentReview\Support\ReviewPolicyResolver;

final class DecideContentReviewAction
{
    public function __construct(
        private readonly ContentReviewDecisionEngine $engine,
        private readonly ReviewPolicyResolver $policies,
        private readonly AutomationEligibilityResolver $eligibility,
        private readonly ApplyContentReviewDecisionAction $apply,
    ) {}

    public function execute(
        ContentReview $review,
        ?StructuredReviewResult $result,
        DeterministicCheckResult $checks,
        ReviewPolicy $policy,
        ?ContentReviewErrorCode $error,
    ): ContentReview {
        $decision = $this->engine->decide(
            $result,
            $checks,
            $policy,
            $review->mode,
            $this->eligibility->resolve($review),
            $error,
        );

        return $this->apply->execute($review, $decision, $this->reasonFor($review, $decision->reasonCode));
    }

    public function executeWithoutResult(
        ContentReview $review,
        DeterministicCheckResult $checks,
        ContentReviewErrorCode $error,
    ): ContentReview {
        $policy = $this->policies->activeRecord($review->subject_type)?->toValueObject()
            ?? ReviewPolicy::fromArray([]);

        return $this->execute($review, null, $checks, $policy, $error);
    }

    private function reasonFor(ContentReview $review, string $reasonCode): string
    {
        $summary = trim((string) $review->summary_ar);

        if ($summary !== '') {
            return $summary;
        }

        $label = trim((string) __('content_review.reasons.'.$reasonCode));

        return $label === '' || $label === 'content_review.reasons.'.$reasonCode
            ? (string) __('content_review.outcomes.escalated_to_human')
            : $label;
    }
}
