<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Support;

use App\Domain\ContentReview\Enums\ContentReviewErrorCode;
use App\Domain\ContentReview\ValueObjects\ReviewSettings;
use App\DTO\ContentReview\CapacityLease;

/**
 * Whether we may spend on a call right now, and the paired cleanup afterwards.
 *
 * The budget check comes before the slot so a call we were never going to make does not first
 * occupy a worker; the reservation comes last because it is the only one taken under a lock.
 * Acquiring all of it behind one call is what makes the release symmetrical — a partially
 * acquired lease used to have to be unwound by hand at the call site, which is exactly the
 * shape that leaks a slot the next time a branch is added.
 */
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
