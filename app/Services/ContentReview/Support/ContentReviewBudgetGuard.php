<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Support;

use App\Domain\ContentReview\ValueObjects\ReviewSettings;
use App\Repositories\ContentReview\ContentReviewRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class ContentReviewBudgetGuard
{
    private const RESERVED_PREFIX = 'content_review:budget:reserved:';

    private const RESERVATION_PREFIX = 'content_review:budget:reservation:';

    private const ALERT_PREFIX = 'content_review:budget:alerted:';

    private const LOCK_KEY = 'content_review:budget:lock';

    public function __construct(
        private readonly ContentReviewRepository $reviews,
        private readonly ProviderCostCalculator $costs,
    ) {}

    public function estimateMicros(string $provider, string $model, int $maxOutputTokens): int
    {
        $inputTokens = max(0, (int) config('content_review.budget.estimated_input_tokens', 4000));
        $estimate = $this->costs->costMicros($provider, $model, $inputTokens, max(0, $maxOutputTokens));

        if ($estimate === null) {
            return max(0, (int) config('content_review.budget.fallback_estimate_micros', 5000));
        }

        return $estimate;
    }

    public function spentMicros(string $period): int
    {
        return $this->reviews->costMicrosCompletedSince($this->periodStart($period));
    }

    public function reservedMicros(string $period): int
    {
        return max(0, (int) Cache::get(self::RESERVED_PREFIX.$this->periodKey($period), 0));
    }

    public function remainingMicros(ReviewSettings $settings, string $period): int
    {
        $budget = $this->budgetFor($settings, $period);

        if ($budget <= 0) {
            return 0;
        }

        return max(0, $budget - $this->spentMicros($period) - $this->reservedMicros($period));
    }

    public function exhaustedPeriod(ReviewSettings $settings, string $provider, string $model, int $maxOutputTokens): ?string
    {
        $estimate = $this->estimateMicros($provider, $model, $maxOutputTokens);

        foreach (['daily', 'monthly'] as $period) {
            if ($this->remainingMicros($settings, $period) < $estimate) {
                return $period;
            }
        }

        return null;
    }

    public function reserve(ReviewSettings $settings, string $provider, string $model, int $maxOutputTokens): ?string
    {
        $estimate = $this->estimateMicros($provider, $model, $maxOutputTokens);
        $ttl = max(60, (int) config('content_review.budget.reservation_ttl_seconds', 900));
        $lockSeconds = max(1, (int) config('content_review.budget.lock_seconds', 5));

        $lock = Cache::lock(self::LOCK_KEY, $lockSeconds);

        if (! $lock->block($lockSeconds, static fn (): bool => true)) {
            return null;
        }

        try {
            foreach (['daily', 'monthly'] as $period) {
                if ($this->remainingMicros($settings, $period) < $estimate) {
                    return null;
                }
            }

            foreach (['daily', 'monthly'] as $period) {
                $key = self::RESERVED_PREFIX.$this->periodKey($period);
                Cache::put($key, $this->reservedMicros($period) + $estimate, $ttl);
            }

            $reservationId = (string) Str::ulid();
            Cache::put(self::RESERVATION_PREFIX.$reservationId, [
                'estimate' => $estimate,
                'daily' => $this->periodKey('daily'),
                'monthly' => $this->periodKey('monthly'),
            ], $ttl);

            return $reservationId;
        } finally {
            $lock->release();
        }
    }

    public function release(?string $reservationId): void
    {
        if ($reservationId === null) {
            return;
        }

        $reservation = Cache::pull(self::RESERVATION_PREFIX.$reservationId);

        if (! is_array($reservation)) {
            return;
        }

        $estimate = max(0, (int) ($reservation['estimate'] ?? 0));
        $ttl = max(60, (int) config('content_review.budget.reservation_ttl_seconds', 900));
        $lockSeconds = max(1, (int) config('content_review.budget.lock_seconds', 5));

        $lock = Cache::lock(self::LOCK_KEY, $lockSeconds);

        if (! $lock->block($lockSeconds, static fn (): bool => true)) {
            return;
        }

        try {
            foreach (['daily', 'monthly'] as $period) {
                $bucket = (string) ($reservation[$period] ?? '');

                if ($bucket === '') {
                    continue;
                }

                $key = self::RESERVED_PREFIX.$bucket;
                $current = max(0, (int) Cache::get($key, 0));
                Cache::put($key, max(0, $current - $estimate), $ttl);
            }
        } finally {
            $lock->release();
        }
    }

    public function shouldAlert(string $period): bool
    {
        $ttl = $period === 'daily' ? 86_400 : 2_592_000;

        return Cache::add(self::ALERT_PREFIX.$this->periodKey($period), 1, $ttl);
    }

    public function budgetFor(ReviewSettings $settings, string $period): int
    {
        return $settings->budgetMicros($period);
    }

    /**
     * The one reading of a budget period, so the health and metrics endpoints can never
     * disagree about the same number. An unset budget means unlimited, not exhausted.
     *
     * @return array<string, int|bool|null>
     */
    public function periodSnapshot(ReviewSettings $settings, string $period, int $unpricedReviews): array
    {
        $budget = $this->budgetFor($settings, $period);
        $spent = $this->spentMicros($period);
        $reserved = $this->reservedMicros($period);
        $remaining = $this->remainingMicros($settings, $period);

        return [
            'budget_micros' => $budget,
            'spent_micros' => $spent,
            'reserved_micros' => $reserved,
            'remaining_micros' => $remaining,
            'utilization_percent' => $budget <= 0 ? null : intdiv(($spent + $reserved) * 100, $budget),
            'exhausted' => $budget > 0 && $remaining <= 0,
            'unpriced_reviews' => $unpricedReviews,
            'totals_complete' => $unpricedReviews === 0,
        ];
    }

    private function periodStart(string $period): Carbon
    {
        return $period === 'daily'
            ? Carbon::now()->startOfDay()
            : Carbon::now()->startOfMonth();
    }

    private function periodKey(string $period): string
    {
        return $period === 'daily'
            ? 'daily:'.Carbon::now()->format('Y-m-d')
            : 'monthly:'.Carbon::now()->format('Y-m');
    }
}
