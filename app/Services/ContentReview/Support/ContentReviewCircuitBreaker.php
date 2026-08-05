<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Support;

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

    public function recordFailure(array $settings): void
    {
        $breaker = $this->breakerSettings($settings);

        $failures = (int) Cache::get(self::FAILURE_KEY, 0) + 1;
        Cache::put(self::FAILURE_KEY, $failures, $breaker['window_seconds']);

        if ($failures < $breaker['failure_threshold']) {
            return;
        }

        Cache::put(self::OPEN_KEY, time() + $breaker['open_seconds'], $breaker['open_seconds']);
    }

    public function shouldAlert(array $settings): bool
    {
        $breaker = $this->breakerSettings($settings);

        return Cache::add(self::ALERT_KEY, 1, $breaker['open_seconds']);
    }

    public function reset(): void
    {
        $this->recordSuccess();
    }

    private function breakerSettings(array $settings): array
    {
        $defaults = (array) config('content_review.defaults.circuit_breaker');
        $configured = $settings['circuit_breaker'] ?? [];
        $configured = is_array($configured) ? $configured : [];

        $merged = array_replace($defaults, $configured);

        return [
            'failure_threshold' => max(1, (int) ($merged['failure_threshold'] ?? 5)),
            'window_seconds' => max(1, (int) ($merged['window_seconds'] ?? 300)),
            'open_seconds' => max(1, (int) ($merged['open_seconds'] ?? 600)),
        ];
    }
}
