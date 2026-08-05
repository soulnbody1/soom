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
    case ContentUnavailable = 'content_unavailable';
    case ImageFetchFailed = 'image_fetch_failed';
    case BudgetExhausted = 'budget_exhausted';
    case CircuitOpen = 'circuit_open';
    case PolicyMissing = 'policy_missing';
    case SubjectNotReviewable = 'subject_not_reviewable';
    case ContentChanged = 'content_changed';
    case UnknownError = 'unknown_error';

    public function isRetryable(): bool
    {
        return in_array($this, [
            self::ProviderTimeout,
            self::ProviderRateLimited,
            self::ProviderUnavailable,
            self::ImageFetchFailed,
            self::UnknownError,
        ], true);
    }
}
