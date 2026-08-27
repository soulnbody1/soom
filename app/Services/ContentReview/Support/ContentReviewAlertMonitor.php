<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Support;

use App\Domain\ContentReview\Enums\ReviewableSubjectType;
use App\Domain\ContentReview\ValueObjects\ReviewSettings;
use App\Repositories\ContentReview\ContentReviewMetricsRepository;
use App\Services\ContentReview\Contracts\ContentReviewEventPublisher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

final class ContentReviewAlertMonitor
{
    public const QUEUE_DELAY_HIGH = 'queue_delay_high';

    public const CIRCUIT_OPEN = 'circuit_open';

    public const BUDGET_EXHAUSTED = 'budget_exhausted';

    public const PROVIDER_UNAVAILABLE = 'provider_unavailable';

    public const INVALID_OUTPUT_SPIKE = 'invalid_output_spike';

    public const ESCALATION_BACKLOG = 'escalation_backlog';

    private const STATE_PREFIX = 'content_review:alert:state:';

    private const EVALUATION_KEY = 'content_review:alert:evaluation';

    private const EVENTS = [
        self::QUEUE_DELAY_HIGH => 'content_review.queue_delay_high',
        self::CIRCUIT_OPEN => 'content_review.circuit_open',
        self::BUDGET_EXHAUSTED => 'content_review.budget_exhausted',
        self::PROVIDER_UNAVAILABLE => 'content_review.provider_unavailable',
        self::INVALID_OUTPUT_SPIKE => 'content_review.invalid_output_spike',
        self::ESCALATION_BACKLOG => 'content_review.escalation_backlog',
    ];

    public function __construct(
        private readonly ContentReviewMetricsRepository $metrics,
        private readonly ContentReviewCircuitBreaker $breaker,
        private readonly ContentReviewBudgetGuard $budget,
        private readonly ReviewModeResolver $modes,
        private readonly ContentReviewEventPublisher $events,
    ) {}

    /**
     * @return array<int, string>
     */
    public static function codes(): array
    {
        return array_keys(self::EVENTS);
    }

    public static function eventFor(string $code): ?string
    {
        return self::EVENTS[$code] ?? null;
    }

    /**
     * Read only. Never publishes and never mutates the stored state, so a dashboard
     * request can render the banner without emitting notifications.
     */
    public function states(ReviewableSubjectType $type): array
    {
        $observations = $this->observe($type);
        $states = [];

        foreach ($observations as $code => $observation) {
            $stored = $this->storedState($code);

            $states[] = [
                'code' => $code,
                'active' => $observation['active'],
                'severity' => $observation['severity'],
                'observed' => $observation['observed'],
                'threshold' => $observation['threshold'],
                'since' => $observation['active'] ? ($stored['since'] ?? null) : null,
                'last_alerted_at' => $stored['last_alerted_at'] ?? null,
            ];
        }

        return $states;
    }

    /**
     * Edge triggered: an alert is published when a condition turns on, re-published only
     * after the repeat window, and followed by a single recovery event when it clears.
     */
    public function sweep(ReviewableSubjectType $type): array
    {
        $observations = $this->observe($type, false);
        $now = Carbon::now();
        $published = [];
        $recovered = [];

        foreach ($observations as $code => $observation) {
            $stored = $this->storedState($code);
            $wasActive = ($stored['active'] ?? false) === true;

            if ($observation['active'] === true) {
                if ($this->shouldPublish($stored, $wasActive, $now)) {
                    $this->publishAlert($code, $observation);
                    $published[] = $code;
                    $stored['last_alerted_at'] = $now->toIso8601String();
                }

                $stored['active'] = true;
                $stored['since'] = $wasActive ? ($stored['since'] ?? $now->toIso8601String()) : $now->toIso8601String();
                $this->storeState($code, $stored);

                continue;
            }

            if ($wasActive) {
                $this->publishRecovery($code);
                $recovered[] = $code;
            }

            $this->forgetState($code);
        }

        return ['alerted' => $published, 'recovered' => $recovered];
    }

    public function reset(): void
    {
        Cache::forget(self::EVALUATION_KEY);

        foreach (self::codes() as $code) {
            $this->forgetState($code);
        }
    }

    private function observe(ReviewableSubjectType $type, bool $cached = true): array
    {
        if (! $cached) {
            return $this->evaluate($type);
        }

        $seconds = max(1, (int) config('content_review.alerts.evaluation_cache_seconds', 60));

        return Cache::remember(self::EVALUATION_KEY.':'.$type->value, $seconds, fn (): array => $this->evaluate($type));
    }

    private function evaluate(ReviewableSubjectType $type): array
    {
        $settings = $this->modes->effectiveSettings($type);
        $alerts = (array) config('content_review.alerts');

        return [
            self::QUEUE_DELAY_HIGH => $this->queueDelay($alerts),
            self::CIRCUIT_OPEN => $this->circuit(),
            self::BUDGET_EXHAUSTED => $this->budgetState($settings),
            self::PROVIDER_UNAVAILABLE => $this->providerFailures($alerts),
            self::INVALID_OUTPUT_SPIKE => $this->invalidOutput($alerts),
            self::ESCALATION_BACKLOG => $this->escalationBacklog($alerts),
        ];
    }

    private function queueDelay(array $alerts): array
    {
        $threshold = max(1, (int) ($alerts['queue_delay_seconds'] ?? 600));
        $snapshot = $this->metrics->queueSnapshot();
        $age = $snapshot['oldest_queued_age_seconds'];

        return $this->observation($age !== null && $age > $threshold, 'warning', $age, $threshold);
    }

    private function circuit(): array
    {
        return $this->observation($this->breaker->isOpen(), 'critical', $this->breaker->failureCount(), null);
    }

    private function budgetState(ReviewSettings $settings): array
    {
        $exhausted = false;

        foreach (['daily', 'monthly'] as $period) {
            if ($this->budget->budgetFor($settings, $period) > 0 && $this->budget->remainingMicros($settings, $period) <= 0) {
                $exhausted = true;
            }
        }

        return $this->observation($exhausted, 'critical', null, null);
    }

    private function providerFailures(array $alerts): array
    {
        $threshold = max(1, (int) ($alerts['permanent_failure_threshold'] ?? 5));
        $hours = max(1, (int) ($alerts['permanent_failure_window_hours'] ?? 1));
        $observed = $this->metrics->permanentProviderFailuresSince(Carbon::now()->subHours($hours));

        return $this->observation($observed >= $threshold, 'critical', $observed, $threshold);
    }

    private function invalidOutput(array $alerts): array
    {
        $percent = max(1, (int) ($alerts['invalid_output_percent'] ?? 10));
        $minSample = max(1, (int) ($alerts['invalid_output_min_sample'] ?? 50));
        $hours = max(1, (int) ($alerts['invalid_output_window_hours'] ?? 24));

        $attempts = $this->metrics->providerAttemptsSince(Carbon::now()->subHours($hours));
        $sample = $attempts['sample'];
        $observed = $sample === 0 ? 0 : intdiv($attempts['invalid_output'] * 100, $sample);

        return $this->observation($sample >= $minSample && $observed > $percent, 'warning', $observed, $percent);
    }

    private function escalationBacklog(array $alerts): array
    {
        $threshold = max(1, (int) ($alerts['escalation_backlog_threshold'] ?? 50));
        $observed = $this->metrics->escalationBacklog();

        return $this->observation($observed > $threshold, 'warning', $observed, $threshold);
    }

    private function observation(bool $active, string $severity, ?int $observed, ?int $threshold): array
    {
        return [
            'active' => $active,
            'severity' => $severity,
            'observed' => $observed,
            'threshold' => $threshold,
        ];
    }

    private function shouldPublish(array $stored, bool $wasActive, Carbon $now): bool
    {
        if (! $wasActive) {
            return true;
        }

        $last = $stored['last_alerted_at'] ?? null;

        if (! is_string($last) || $last === '') {
            return true;
        }

        $repeat = max(60, (int) config('content_review.alerts.repeat_seconds', 21_600));

        return Carbon::parse($last)->addSeconds($repeat)->lessThanOrEqualTo($now);
    }

    private function publishAlert(string $code, array $observation): void
    {
        $event = self::EVENTS[$code] ?? null;

        if ($event === null) {
            return;
        }

        $this->events->publishOperational($event, [
            'alert_code' => $code,
            'severity' => $observation['severity'],
            'observed' => $observation['observed'],
            'threshold' => $observation['threshold'],
            'subject_label' => __('content_review.alerts.'.$code),
        ]);
    }

    private function publishRecovery(string $code): void
    {
        $this->events->publishOperational('content_review.recovered', [
            'alert_code' => $code,
            'subject_label' => __('content_review.alerts.'.$code),
        ]);
    }

    private function storedState(string $code): array
    {
        $stored = Cache::get(self::STATE_PREFIX.$code);

        return is_array($stored) ? $stored : [];
    }

    private function storeState(string $code, array $state): void
    {
        $ttl = max(300, (int) config('content_review.alerts.state_ttl_seconds', 604_800));

        Cache::put(self::STATE_PREFIX.$code, $state, $ttl);
    }

    private function forgetState(string $code): void
    {
        Cache::forget(self::STATE_PREFIX.$code);
    }
}
