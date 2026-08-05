<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Support;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

final class ContentReviewConcurrencyLimiter
{
    private const SLOT_PREFIX = 'content_review:slot:';

    private array $held = [];

    public function acquire(array $settings): ?string
    {
        $max = $this->maxConcurrent($settings);
        $ttl = max(30, (int) config('content_review.concurrency.slot_ttl_seconds', 900));

        for ($slot = 0; $slot < $max; $slot++) {
            $name = self::SLOT_PREFIX.$slot;
            $lock = Cache::lock($name, $ttl);

            if ($lock->get()) {
                $this->held[$name] = $lock;

                return $name;
            }
        }

        return null;
    }

    public function release(?string $slot): void
    {
        if ($slot === null) {
            return;
        }

        $lock = $this->held[$slot] ?? null;

        if ($lock instanceof Lock) {
            $lock->release();
        }

        unset($this->held[$slot]);
    }

    public function releaseDelaySeconds(): int
    {
        return max(1, (int) config('content_review.concurrency.release_delay_seconds', 30));
    }

    public function maxConcurrent(array $settings): int
    {
        $defaults = (array) config('content_review.defaults');

        return max(1, (int) ($settings['max_concurrent'] ?? $defaults['max_concurrent'] ?? 5));
    }
}
