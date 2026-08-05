<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Support;

use App\Domain\ContentReview\Enums\ContentReviewStatus;
use App\Domain\ContentReview\Enums\ReviewableSubjectType;
use App\Repositories\ContentReview\ContentReviewRepository;
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
        private readonly ContentReviewRepository $reviews,
    ) {}

    public function report(ReviewableSubjectType $type, bool $includeBudget = false): array
    {
        $settings = $this->modes->effectiveSettings($type);
        $mode = $this->modes->resolve($type);
        $provider = $this->providerName($settings);
        $model = $this->model($settings);

        $report = [
            'enabled' => config('content_review.enabled') === true,
            'configured' => $this->isConfigured($provider),
            'provider' => $provider,
            'model' => $model,
            'mode' => $mode->value,
            'mode_label' => __('content_review.modes.'.$mode->value),
            'available_providers' => $this->providers->available(),
            'circuit' => [
                'state' => $this->breaker->isOpen() ? 'open' : 'closed',
                'failure_count' => $this->breaker->failureCount(),
                'open_until' => $this->openUntil(),
            ],
            'last_success_at' => $this->journal->lastSuccessAt()?->toIso8601String(),
            'last_failure_at' => $this->journal->lastFailureAt()?->toIso8601String(),
            'last_failure_code' => $this->journal->lastFailureCode(),
            'queue' => [
                'max_concurrent' => $this->limiter->maxConcurrent($settings),
                'queued' => $this->reviews->countByStatus(ContentReviewStatus::Queued),
                'running' => $this->reviews->countByStatus(ContentReviewStatus::Running),
                'oldest_queued_at' => $this->reviews->oldestQueuedAt()?->toIso8601String(),
            ],
        ];

        if ($includeBudget) {
            $report['budget'] = [
                'currency' => (string) config('content_review.pricing.currency', 'USD'),
                'daily' => $this->budgetPeriod($settings, 'daily'),
                'monthly' => $this->budgetPeriod($settings, 'monthly'),
            ];
        }

        return $report;
    }

    private function budgetPeriod(array $settings, string $period): array
    {
        return [
            'budget_micros' => $this->budget->budgetFor($settings, $period),
            'spent_micros' => $this->budget->spentMicros($period),
            'reserved_micros' => $this->budget->reservedMicros($period),
            'remaining_micros' => $this->budget->remainingMicros($settings, $period),
            'exhausted' => $this->budget->remainingMicros($settings, $period) <= 0,
        ];
    }

    private function openUntil(): ?string
    {
        $timestamp = $this->breaker->openUntil();

        return $timestamp === null ? null : Carbon::createFromTimestamp($timestamp)->toIso8601String();
    }

    private function isConfigured(string $provider): bool
    {
        return match ($provider) {
            'fake' => true,
            'anthropic' => trim((string) config('services.anthropic.api_key')) !== '',
            default => false,
        };
    }

    private function providerName(array $settings): string
    {
        $provider = $settings['provider'] ?? null;

        return is_string($provider) && $provider !== ''
            ? $provider
            : (string) config('content_review.provider', 'fake');
    }

    private function model(array $settings): string
    {
        $model = $settings['model'] ?? null;

        return is_string($model) && $model !== ''
            ? $model
            : (string) config('content_review.model', 'claude-sonnet-5');
    }
}
