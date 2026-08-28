<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Support;

use App\Domain\ContentReview\Enums\ContentReviewErrorCode;
use App\Domain\ContentReview\ValueObjects\ReviewSettings;
use App\DTO\ContentReview\CapacityLease;

final class ProviderCallCapacity
{
    public function __construct(
        private readonly ContentReviewBudgetGuard $budget,
        private readonly ContentReviewConcurrencyLimiter $limiter,
    ) {}

    public function acquire(ReviewSettings $settings, string $provider, string $model, int $maxOutputTokens): CapacityLease
    {
        if ($this->budget->exhaustedPeriod($settings, $provider, $model, $maxOutputTokens) !== null) {
            return CapacityLease::refused(ContentReviewErrorCode::BudgetExhausted);
        }

        $slot = $this->limiter->acquire($settings);

        if ($slot === null) {
            return CapacityLease::noSlot();
        }

        $reservation = $this->budget->reserve($settings, $provider, $model, $maxOutputTokens);

        if ($reservation === null) {
            $this->limiter->release($slot);

            return CapacityLease::refused(ContentReviewErrorCode::BudgetExhausted);
        }

        return CapacityLease::granted($slot, $reservation);
    }

    public function release(CapacityLease $lease): void
    {
        $this->budget->release($lease->reservation);
        $this->limiter->release($lease->slot);
    }
}
