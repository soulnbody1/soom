<?php

declare(strict_types=1);

namespace App\Domain\ContentReview\ValueObjects;

use JsonSerializable;

/**
 * The effective knobs for one subject type: published settings laid over the config defaults.
 *
 * It wraps the merged array rather than declaring typed properties for two reasons. The admin
 * settings endpoint serialises this straight into its response, so the array has to survive
 * intact; and a published settings row may legitimately carry keys this class has no accessor
 * for yet. What the accessors add is the clamping — every bound lives here once, instead of
 * being re-derived by each consumer.
 */
final readonly class ReviewSettings implements JsonSerializable
{
    private const DEFAULT_BACKOFF_SECONDS = [60, 300, 900];

    public function __construct(public array $settings) {}

    /**
     * Published values win, but only where they were actually set; a null means "inherit".
     */
    public static function merge(array $defaults, array $published = []): self
    {
        return new self(array_replace($defaults, array_filter(
            $published,
            static fn ($value): bool => $value !== null
        )));
    }

    public function provider(): ?string
    {
        $provider = $this->settings['provider'] ?? null;

        return is_string($provider) && $provider !== '' ? $provider : null;
    }

    public function model(): ?string
    {
        $model = $this->settings['model'] ?? null;

        return is_string($model) && $model !== '' ? $model : null;
    }

    public function timeoutSeconds(): int
    {
        return max(5, $this->int('timeout_seconds', 45));
    }

    public function maxAttempts(): int
    {
        return max(1, min(255, $this->int('max_attempts', 3)));
    }

    public function maxOutputTokens(): int
    {
        return max(256, $this->int('max_output_tokens', 2000));
    }

    public function maxConcurrent(): int
    {
        return max(1, $this->int('max_concurrent', 5));
    }

    public function analyzeImages(): bool
    {
        return ($this->settings['analyze_images'] ?? true) === true;
    }

    /**
     * @return array<int, int>
     */
    public function backoffSeconds(): array
    {
        $backoff = $this->settings['backoff_seconds'] ?? null;

        if (! is_array($backoff) || $backoff === []) {
            $backoff = self::DEFAULT_BACKOFF_SECONDS;
        }

        return array_values(array_map(static fn ($value): int => max(1, (int) $value), $backoff));
    }

    public function budgetMicros(string $period): int
    {
        return max(0, $this->int($period === 'daily' ? 'daily_budget_micros' : 'monthly_budget_micros', 0));
    }

    /**
     * @return array{failure_threshold: int, window_seconds: int, open_seconds: int}
     */
    public function circuitBreaker(): array
    {
        $configured = $this->settings['circuit_breaker'] ?? [];
        $merged = array_replace(
            (array) config('content_review.defaults.circuit_breaker'),
            is_array($configured) ? $configured : []
        );

        return [
            'failure_threshold' => max(1, (int) ($merged['failure_threshold'] ?? 5)),
            'window_seconds' => max(1, (int) ($merged['window_seconds'] ?? 300)),
            'open_seconds' => max(1, (int) ($merged['open_seconds'] ?? 600)),
        ];
    }

    public function toArray(): array
    {
        return $this->settings;
    }

    public function jsonSerialize(): array
    {
        return $this->settings;
    }

    private function int(string $key, int $fallback): int
    {
        $value = $this->settings[$key] ?? null;

        return is_numeric($value) ? (int) $value : $fallback;
    }
}
