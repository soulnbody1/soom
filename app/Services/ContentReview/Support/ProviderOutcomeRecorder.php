<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Support;

use App\Domain\ContentReview\Enums\ContentReviewErrorCode;
use App\Domain\ContentReview\ValueObjects\ReviewSettings;
use App\Services\ContentReview\Contracts\ContentReviewEventPublisher;

/**
 * What one provider call taught us about the provider itself.
 *
 * The circuit breaker, the health journal and the unavailability alert are three views of the
 * same fact and have to move together — a failure counted against the breaker but missing from
 * the journal makes the health endpoint disagree with the behaviour operators are seeing.
 * Keeping them behind one call is what makes that impossible to get half right.
 */
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

    /**
     * How long to wait before a blocked review is worth attempting again. Floored so a review
     * can never be released back with no delay and spin against a still-open circuit.
     */
    public function secondsUntilAvailable(): int
    {
        return max(5, $this->breaker->secondsUntilClosed());
    }

    public function recordSuccess(): void
    {
        $this->breaker->recordSuccess();
        $this->journal->recordSuccess();
    }

    /**
     * The alert fires only on the transition into an open circuit, not on every failure that
     * follows it, so an outage produces one notification rather than one per queued review.
     */
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
