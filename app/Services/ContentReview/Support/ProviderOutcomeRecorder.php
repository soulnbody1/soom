<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Support;

use App\Domain\ContentReview\Enums\ContentReviewErrorCode;
use App\Domain\ContentReview\ValueObjects\ReviewSettings;
use App\Services\ContentReview\Contracts\ContentReviewEventPublisher;

final class ProviderOutcomeRecorder
{
    public function __construct(
        private readonly ContentReviewCircuitBreaker $breaker,
        private readonly ProviderHealthJournal $journal,
        private readonly ContentReviewEventPublisher $events,
    ) {}

    public function isUnavailable(): bool
    {
        return $this->breaker->isOpen();
    }

    public function secondsUntilAvailable(): int
    {
        return max(5, $this->breaker->secondsUntilClosed());
    }

    public function recordSuccess(): void
    {
        $this->breaker->recordSuccess();
        $this->journal->recordSuccess();
    }

    public function recordFailure(ReviewSettings $settings, ContentReviewErrorCode $code): void
    {
        $this->breaker->recordFailure($settings);
        $this->journal->recordFailure($code);

        if (! $this->breaker->isOpen() || ! $this->breaker->shouldAlert($settings)) {
            return;
        }

        $this->events->publishOperational('content_review.provider_unavailable', [
            'error_code' => $code->value,
        ]);
    }
}
