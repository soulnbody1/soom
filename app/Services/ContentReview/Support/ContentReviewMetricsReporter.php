<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Support;

use App\Domain\ContentReview\Enums\ContentReviewErrorCode;
use App\Domain\ContentReview\Enums\ContentReviewOutcome;
use App\Domain\ContentReview\Enums\ContentReviewStatus;
use App\Domain\ContentReview\Enums\ReviewableSubjectType;
use App\Domain\ContentReview\Enums\ReviewMode;
use App\DTO\ContentReview\MetricsRange;
use App\Repositories\ContentReview\ContentReviewMetricsRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

final class ContentReviewMetricsReporter
{
    private const CACHE_PREFIX = 'content_review:metrics:';

    public function __construct(
        private readonly ContentReviewMetricsRepository $metrics,
        private readonly ContentReviewHealthReporter $health,
        private readonly ContentReviewAlertMonitor $alerts,
        private readonly ContentReviewBudgetGuard $budget,
        private readonly ReviewModeResolver $modes,
        private readonly ContentReviewWorkerHeartbeat $heartbeat,
    ) {}

    public function report(ReviewableSubjectType $type, MetricsRange $range, bool $includeCosts = false): array
    {
        $aggregates = $this->aggregates($range);

        $report = [
            'range' => $range->toArray(),
            'generated_at' => Carbon::now()->toIso8601String(),
            'volume' => $aggregates['volume'],
            'outcomes' => $aggregates['outcomes'],
            'modes' => $aggregates['modes'],
            'provider' => $aggregates['provider'],
            'retries' => $aggregates['retries'],
            'latency' => $aggregates['latency'],
            'human' => $aggregates['human'],
            'images' => $aggregates['images'],
            'rates' => $aggregates['rates'],
            'tokens' => $aggregates['tokens'],
            'queue' => $this->queue(),
            'health' => $this->health->report($type, $includeCosts),
            'alerts' => $this->alerts->states($type),
        ];

        if ($includeCosts) {
            $report['cost'] = $this->cost($type, $aggregates);
        }

        return $report;
    }

    private function aggregates(MetricsRange $range): array
    {
        $seconds = max(1, (int) config('content_review.metrics.cache_seconds', 60));

        return Cache::remember(
            self::CACHE_PREFIX.$range->cacheKey(),
            $seconds,
            fn (): array => $this->computeAggregates($range)
        );
    }

    private function computeAggregates(MetricsRange $range): array
    {
        $statuses = $this->metrics->statusCounts($range);
        $outcomes = $this->metrics->outcomeCounts($range);
        $modes = $this->metrics->modeCounts($range);
        $errors = $this->metrics->errorCodeCounts($range);
        $totals = $this->metrics->totals($range);
        $duration = $this->metrics->percentiles($range, 'duration_ms');
        $queueDelay = $this->metrics->percentiles($range, 'queue_delay_ms');
        $human = $this->metrics->humanDecisionCounts($range);

        $volume = $this->bucket($statuses, ContentReviewStatus::cases());
        $volume['total'] = $totals['review_count'];

        $outcomeCounts = $this->bucket($outcomes, ContentReviewOutcome::cases());
        $outcomeCounts['pending'] = max(0, $totals['review_count'] - array_sum($outcomeCounts));

        $provider = $this->provider($volume, $errors);
        $images = $this->images($totals);

        return [
            'volume' => $volume,
            'outcomes' => $outcomeCounts,
            'modes' => $this->bucket($modes, ReviewMode::cases()),
            'provider' => $provider,
            'retries' => [
                'reviews_retried' => $totals['retried_reviews'],
                'extra_attempts' => $totals['extra_attempts'],
            ],
            'latency' => [
                'average_duration_ms' => $totals['average_duration_ms'],
                'p50_duration_ms' => $duration['p50'],
                'p95_duration_ms' => $duration['p95'],
                'duration_sample' => $duration['sample'],
                'average_queue_delay_ms' => $totals['average_queue_delay_ms'],
                'p50_queue_delay_ms' => $queueDelay['p50'],
                'p95_queue_delay_ms' => $queueDelay['p95'],
                'queue_delay_sample' => $queueDelay['sample'],
            ],
            'human' => $human,
            'images' => $images,
            'tokens' => [
                'input' => $totals['input_tokens'],
                'output' => $totals['output_tokens'],
            ],
            'rates' => $this->rates($volume, $outcomeCounts, $provider, $human, $images),
            'cost_totals' => [
                'cost_micros' => $totals['cost_micros'],
                'priced_reviews' => $totals['priced_reviews'],
                'unpriced_reviews' => $totals['unpriced_reviews'],
            ],
        ];
    }

    private function provider(array $volume, array $errors): array
    {
        $failuresByCode = [];

        foreach ($this->metrics->providerErrorCodes() as $code) {
            $count = (int) ($errors[$code] ?? 0);

            if ($count > 0) {
                $failuresByCode[$code] = $count;
            }
        }

        $failed = array_sum($failuresByCode);
        $succeeded = $volume['completed'];

        return [
            'calls' => $succeeded + $failed,
            'succeeded' => $succeeded,
            'failed' => $failed,
            'invalid_structured_output' => (int) ($errors[ContentReviewErrorCode::InvalidStructuredOutput->value] ?? 0),
            'rate_limited' => (int) ($errors[ContentReviewErrorCode::ProviderRateLimited->value] ?? 0),
            'timed_out' => (int) ($errors[ContentReviewErrorCode::ProviderTimeout->value] ?? 0),
            'failures_by_code' => $failuresByCode,
            'guard_blocked' => [
                ContentReviewErrorCode::CircuitOpen->value => (int) ($errors[ContentReviewErrorCode::CircuitOpen->value] ?? 0),
                ContentReviewErrorCode::BudgetExhausted->value => (int) ($errors[ContentReviewErrorCode::BudgetExhausted->value] ?? 0),
            ],
        ];
    }

    private function images(array $totals): array
    {
        $analyzed = $totals['images_analyzed'];
        $hits = $totals['image_cache_hits'];

        return [
            'analyzed' => $analyzed,
            'cache_hits' => $hits,
            'cache_hit_percent' => $this->percent($hits, $analyzed + $hits),
        ];
    }

    private function rates(array $volume, array $outcomes, array $provider, array $human, array $images): array
    {
        $decided = $outcomes[ContentReviewOutcome::AutoApproved->value]
            + $outcomes[ContentReviewOutcome::AutoRejected->value]
            + $outcomes[ContentReviewOutcome::EscalatedToHuman->value];

        $withRecommendation = $human['confirmations'] + $human['overrides'];

        return [
            'automatic_decision_percent' => $this->percent(
                $outcomes[ContentReviewOutcome::AutoApproved->value] + $outcomes[ContentReviewOutcome::AutoRejected->value],
                $decided
            ),
            'escalation_percent' => $this->percent($outcomes[ContentReviewOutcome::EscalatedToHuman->value], $decided),
            'provider_success_percent' => $this->percent($provider['succeeded'], $provider['calls']),
            'provider_failure_percent' => $this->percent($provider['failed'], $provider['calls']),
            'invalid_output_percent' => $this->percent($provider['invalid_structured_output'], $provider['calls']),
            'override_percent' => $this->percent($human['overrides'], $withRecommendation),
            'agreement_percent' => $this->percent($human['confirmations'], $withRecommendation),
            'image_cache_hit_percent' => $images['cache_hit_percent'],
            'superseded_percent' => $this->percent($volume['superseded'], $volume['total']),
        ];
    }

    private function queue(): array
    {
        return array_replace($this->metrics->queueSnapshot(), [
            'last_job_processed_at' => $this->heartbeat->lastJobAt()?->toIso8601String(),
            'seconds_since_last_job' => $this->heartbeat->secondsSinceLastJob(),
        ]);
    }

    private function cost(ReviewableSubjectType $type, array $aggregates): array
    {
        $settings = $this->modes->effectiveSettings($type);
        $totals = $aggregates['cost_totals'];

        return [
            'currency' => (string) config('content_review.pricing.currency', 'USD'),
            'pricing_version' => (string) config('content_review.pricing.version', ''),
            'range_cost_micros' => $totals['cost_micros'],
            'priced_reviews' => $totals['priced_reviews'],
            'unpriced_reviews' => $totals['unpriced_reviews'],
            'totals_complete' => $totals['unpriced_reviews'] === 0,
            'daily' => $this->costPeriod($settings, 'daily', Carbon::now()->startOfDay()),
            'monthly' => $this->costPeriod($settings, 'monthly', Carbon::now()->startOfMonth()),
        ];
    }

    private function costPeriod(array $settings, string $period, Carbon $since): array
    {
        $budget = $this->budget->budgetFor($settings, $period);
        $spent = $this->budget->spentMicros($period);
        $reserved = $this->budget->reservedMicros($period);
        $unpriced = $this->metrics->unpricedSince($since);

        return [
            'budget_micros' => $budget,
            'spent_micros' => $spent,
            'reserved_micros' => $reserved,
            'remaining_micros' => $this->budget->remainingMicros($settings, $period),
            'utilization_percent' => $this->percent($spent + $reserved, $budget),
            'exhausted' => $budget > 0 && $this->budget->remainingMicros($settings, $period) <= 0,
            'unpriced_reviews' => $unpriced,
            'totals_complete' => $unpriced === 0,
        ];
    }

    private function bucket(array $counts, array $cases): array
    {
        $bucket = [];

        foreach ($cases as $case) {
            $bucket[$case->value] = (int) ($counts[$case->value] ?? 0);
        }

        return $bucket;
    }

    private function percent(int $numerator, int $denominator): ?int
    {
        if ($denominator <= 0) {
            return null;
        }

        return intdiv($numerator * 100, $denominator);
    }
}
