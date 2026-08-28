<?php

declare(strict_types=1);

namespace App\DTO\ContentReview;

use App\Domain\ContentReview\Enums\ContentReviewErrorCode;

/**
 * Permission to make one provider call: a concurrency slot and a budget reservation, held
 * together so they are always released together.
 */
final readonly class CapacityLease extends BaseContentReviewDTO
{
    private function __construct(
        public ?string $slot,
        public ?string $reservation,
        public ?ContentReviewErrorCode $refusal,
        public bool $slotUnavailable,
    ) {}

    public static function granted(string $slot, string $reservation): self
    {
        return new self($slot, $reservation, null, false);
    }

    /**
     * Every worker is busy. Nothing is wrong with the review, so it waits its turn.
     */
    public static function noSlot(): self
    {
        return new self(null, null, null, true);
    }

    public static function refused(ContentReviewErrorCode $code, ?string $slot = null): self
    {
        return new self($slot, null, $code, false);
    }

    public function isGranted(): bool
    {
        return $this->slot !== null && $this->reservation !== null;
    }

    public function toArray(): array
    {
        return [
            'granted' => $this->isGranted(),
            'refusal' => $this->refusal?->value,
            'slot_unavailable' => $this->slotUnavailable,
        ];
    }
}
