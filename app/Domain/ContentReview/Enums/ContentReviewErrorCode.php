<?php

declare(strict_types=1);

namespace App\Domain\ContentReview\Enums;

enum ContentReviewErrorCode: string
{
    case ProviderTimeout = 'provider_timeout';
    case ProviderRateLimited = 'provider_rate_limited';
    case ProviderUnavailable = 'provider_unavailable';
    case ProviderAuthFailed = 'provider_auth_failed';
    case InvalidStructuredOutput = 'invalid_structured_output';
    case OutputTruncated = 'provider_output_truncated';
    case ContentUnavailable = 'content_unavailable';
    case ImageFetchFailed = 'image_fetch_failed';
    case BudgetExhausted = 'budget_exhausted';
    case CircuitOpen = 'circuit_open';
    case PolicyMissing = 'policy_missing';
    case SubjectNotReviewable = 'subject_not_reviewable';
    case ContentChanged = 'content_changed';
    case UnknownError = 'unknown_error';

    /**
     * Only conditions that can plausibly differ on the next attempt.
     *
     * UnknownError is deliberately absent. Every transient failure around a provider call
     * already carries a specific code — the transport layer maps connection and HTTP failures
     * itself — so what is left over is a defect in our own request building or response
     * handling. Those fail identically every time, and retrying one spends real money three
     * times to reach the same escalation.
     */
    public function isRetryable(): bool
    {
        return in_array($this, [
            self::ProviderTimeout,
            self::ProviderRateLimited,
            self::ProviderUnavailable,
            self::ImageFetchFailed,
        ], true);
    }

    /**
     * The condition clears on its own, so the review should wait for it rather than be failed
     * and handed to a human. Unlike a retryable failure, nothing went wrong with the review
     * itself and no attempt is consumed.
     */
    public function isTransientBlock(): bool
    {
        return $this === self::CircuitOpen;
    }
}
