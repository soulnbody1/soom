<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Support;

use App\Domain\ContentReview\ValueObjects\ReviewSettings;
use Illuminate\Support\Facades\Cache;

final class ContentReviewCircuitBreaker
{
    private const FAILURE_KEY = 'content_review:circuit:failures';

    private const OPEN_KEY = 'content_review:circuit:open_until';

    private const ALERT_KEY = 'content_review:circuit:alerted';

    public function isOpen(): bool
    {
        return Cache::get(self::OPEN_KEY) !== null;
    }

    public function openUntil(): ?int
    {
        $value = Cache::get(self::OPEN_KEY);

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * How long the circuit still refuses calls. Zero once it has closed, so a caller that has
     * lost the race simply retries immediately rather than waiting on a stale deadline.
     */
    public function secondsUntilClosed(): int
    {
        $openUntil = $this->openUntil();

        return $openUntil === null ? 0 : max(0, $openUntil - time());
    }

    public function failureCount(): int
    {
        return (int) Cache::get(self::FAILURE_KEY, 0);
    }

    public function recordSuccess(): void
    {
        Cache::forget(self::FAILURE_KEY);
        Cache::forget(self::OPEN_KEY);
        Cache::forget(self::ALERT_KEY);
    }

    public function recordFailure(ReviewSettings $settings): void
    {
        $breaker = $settings->circuitBreaker();

        $failures = (int) Cache::get(self::FAILURE_KEY, 0) + 1;
        Cache::put(self::FAILURE_KEY, $failures, $breaker['window_seconds']);

        if ($failures < $breaker['failure_threshold']) {
            return;
        }

        Cache::put(self::OPEN_KEY, time() + $breaker['open_seconds'], $breaker['open_seconds']);
    }

    public function shouldAlert(ReviewSettings $settings): bool
    {
        $breaker = $settings->circuitBreaker();

        return Cache::add(self::ALERT_KEY, 1, $breaker['open_seconds']);
    }

    public function reset(): void
    {
        $this->recordSuccess();
    }
}
