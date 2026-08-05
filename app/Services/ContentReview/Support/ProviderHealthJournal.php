<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Support;

use App\Domain\ContentReview\Enums\ContentReviewErrorCode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

final class ProviderHealthJournal
{
    private const SUCCESS_KEY = 'content_review:provider:last_success';

    private const FAILURE_KEY = 'content_review:provider:last_failure';

    private const TTL_SECONDS = 2_592_000;

    public function recordSuccess(): void
    {
        Cache::put(self::SUCCESS_KEY, Carbon::now()->toIso8601String(), self::TTL_SECONDS);
    }

    public function recordFailure(ContentReviewErrorCode $code): void
    {
        Cache::put(self::FAILURE_KEY, [
            'at' => Carbon::now()->toIso8601String(),
            'code' => $code->value,
        ], self::TTL_SECONDS);
    }

    public function lastSuccessAt(): ?Carbon
    {
        $value = Cache::get(self::SUCCESS_KEY);

        return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
    }

    public function lastFailureAt(): ?Carbon
    {
        $failure = Cache::get(self::FAILURE_KEY);
        $at = is_array($failure) ? ($failure['at'] ?? null) : null;

        return is_string($at) && $at !== '' ? Carbon::parse($at) : null;
    }

    public function lastFailureCode(): ?string
    {
        $failure = Cache::get(self::FAILURE_KEY);
        $code = is_array($failure) ? ($failure['code'] ?? null) : null;

        return is_string($code) && $code !== '' ? $code : null;
    }

    public function reset(): void
    {
        Cache::forget(self::SUCCESS_KEY);
        Cache::forget(self::FAILURE_KEY);
    }
}
