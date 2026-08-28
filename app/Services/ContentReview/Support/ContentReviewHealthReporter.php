<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Support;

use App\Domain\ContentReview\Enums\ReviewableSubjectType;
use App\Domain\ContentReview\ValueObjects\ReviewSettings;
use App\Repositories\ContentReview\ContentReviewMetricsRepository;
use App\Services\ContentReview\Providers\ContentReviewProviderFactory;
use Illuminate\Support\Carbon;

final class ContentReviewHealthReporter
{
    public function __construct(
        private readonly ReviewModeResolver $modes,
        private readonly ContentReviewCircuitBreaker $breaker,
        private readonly ContentReviewBudgetGuard $budget,
        private readonly ContentReviewConcurrencyLimiter $limiter,
        private readonly ContentReviewProviderFactory $providers,
        private readonly ProviderHealthJournal $journal,
        private readonly ContentReviewMetricsRepository $metrics,
        private readonly ContentReviewWorkerHeartbeat $heartbeat,
        private readonly ProviderSelectionResolver $selection,
        private readonly ProviderModelCatalog $catalog,
    ) {}

    public function report(ReviewableSubjectType $type): array
    {
        $settings = $this->modes->effectiveSettings($type);
        $mode = $this->modes->resolve($type);
        $provider = $this->selection->provider($settings);
        $model = $this->selection->model($settings);

        $report = [
            'enabled' => config('content_review.enabled') === true,
            'configured' => $this->providers->isConfigured($provider),
            'provider' => $provider,
            'model' => $model,
            'mode' => $mode->value,
            'mode_label' => __('content_review.modes.'.$mode->value),
            'available_providers' => $this->providers->available(),
            'available_models' => $this->catalog->toArray(),
            'circuit' => [
                'state' => $this->breaker->isOpen() ? 'open' : 'closed',
                'failure_count' => $this->breaker->failureCount(),
                'open_until' => $this->openUntil(),
            ],
            'last_success_at' => $this->journal->lastSuccessAt()?->toIso8601String(),
            'last_failure_at' => $this->journal->lastFailureAt()?->toIso8601String(),
            'last_failure_code' => $this->journal->lastFailureCode(),
            'recent' => $this->recentProviderRates(),
            'queue' => array_replace($this->metrics->queueSnapshot(), [
                'max_concurrent' => $this->limiter->maxConcurrent($settings),
                'last_job_processed_at' => $this->heartbeat->lastJobAt()?->toIso8601String(),
                'seconds_since_last_job' => $this->heartbeat->secondsSinceLastJob(),
            ]),
            'budget' => [
                'currency' => (string) config('content_review.pricing.currency', 'USD'),
                'daily' => $this->budgetPeriod($settings, 'daily', Carbon::now()->startOfDay()),
                'monthly' => $this->budgetPeriod($settings, 'monthly', Carbon::now()->startOfMonth()),
            ],
        ];

        return $report;
    }

    private function recentProviderRates(): array
    {
        $hours = max(1, (int) config('content_review.alerts.invalid_output_window_hours', 24));
        $since = Carbon::now()->subHours($hours);
        $attempts = $this->metrics->providerAttemptsSince($since);
        $failures = $this->metrics->permanentProviderFailuresSince($since);
        $sample = $attempts['completed'] + $failures;

        return [
            'window_hours' => $hours,
            'sample' => $sample,
            'failures' => $failures,
            'invalid_structured_output' => $attempts['invalid_output'],
            'failure_percent' => $sample === 0 ? null : intdiv($failures * 100, $sample),
            'invalid_output_percent' => $sample === 0 ? null : intdiv($attempts['invalid_output'] * 100, $sample),
        ];
    }

    private function budgetPeriod(ReviewSettings $settings, string $period, Carbon $since): array
    {
        return $this->budget->periodSnapshot($settings, $period, $this->metrics->unpricedSince($since));
    }

    private function openUntil(): ?string
    {
        $timestamp = $this->breaker->openUntil();

        return $timestamp === null ? null : Carbon::createFromTimestamp($timestamp)->toIso8601String();
    }
}
