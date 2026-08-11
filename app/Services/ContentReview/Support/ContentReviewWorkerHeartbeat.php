<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

final class ContentReviewWorkerHeartbeat
{
    private const KEY = 'content_review:worker:last_job_at';

    public function record(): void
    {
        Cache::put(self::KEY, Carbon::now()->toIso8601String(), $this->ttlSeconds());
    }

    public function lastJobAt(): ?Carbon
    {
        $value = Cache::get(self::KEY);

        return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
    }

    public function secondsSinceLastJob(): ?int
    {
        $at = $this->lastJobAt();

        return $at === null ? null : max(0, (int) Carbon::now()->diffInSeconds($at, true));
    }

    public function forget(): void
    {
        Cache::forget(self::KEY);
    }

    private function ttlSeconds(): int
    {
        return max(60, (int) config('content_review.heartbeat.ttl_seconds', 86_400));
    }
}
