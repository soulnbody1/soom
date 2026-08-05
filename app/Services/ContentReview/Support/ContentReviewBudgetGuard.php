<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Support;

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

    public function estimateMicros(string $model, int $maxOutputTokens): int
    {
        $inputTokens = max(0, (int) config('content_review.budget.estimated_input_tokens', 4000));
        $estimate = $this->costs->costMicros($model, $inputTokens, max(0, $maxOutputTokens));

        if ($estimate === null) {
            return max(0, (int) config('content_review.budget.fallback_estimate_micros', 5000));
        }

        return $estimate;
    }

    public function spentMicros(string $period): int
    {
        return $this->reviews->totalCostMicrosSince($this->periodStart($period));
    }

    public function reservedMicros(string $period): int
    {
        return max(0, (int) Cache::get(self::RESERVED_PREFIX.$this->periodKey($period), 0));
    }

    public function remainingMicros(array $settings, string $period): int
    {
        $budget = $this->budgetFor($settings, $period);

        if ($budget <= 0) {
            return 0;
        }

        return max(0, $budget - $this->spentMicros($period) - $this->reservedMicros($period));
    }

    public function exhaustedPeriod(array $settings, string $model, int $maxOutputTokens): ?string
    {
        $estimate = $this->estimateMicros($model, $maxOutputTokens);

        foreach (['daily', 'monthly'] as $period) {
            if ($this->remainingMicros($settings, $period) < $estimate) {
                return $period;
            }
        }

        return null;
    }

    public function reserve(array $settings, string $model, int $maxOutputTokens): ?string
    {
        $estimate = $this->estimateMicros($model, $maxOutputTokens);
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

    public function budgetFor(array $settings, string $period): int
    {
        $key = $period === 'daily' ? 'daily_budget_micros' : 'monthly_budget_micros';
        $defaults = (array) config('content_review.defaults');

        return max(0, (int) ($settings[$key] ?? $defaults[$key] ?? 0));
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
