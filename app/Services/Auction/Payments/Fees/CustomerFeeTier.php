<?php

declare(strict_types=1);

namespace App\Services\Auction\Payments\Fees;

use InvalidArgumentException;

final readonly class CustomerFeeTier
{
    public function __construct(
        public int $fromMinor,
        public ?int $toMinor,
        public int $feeMinor,
    ) {
        if ($fromMinor < 0) {
            throw new InvalidArgumentException('A fee tier cannot start below zero.');
        }

        if ($feeMinor < 0) {
            throw new InvalidArgumentException('A fee tier cannot charge a negative fee.');
        }

        if ($toMinor !== null && $toMinor < $fromMinor) {
            throw new InvalidArgumentException('A fee tier cannot end before it starts.');
        }
    }

    public static function fromArray(array $tier): self
    {
        $to = $tier['to_minor'] ?? null;

        return new self(
            fromMinor: (int) ($tier['from_minor'] ?? 0),
            toMinor: $to === null || $to === '' ? null : (int) $to,
            feeMinor: (int) ($tier['fee_minor'] ?? 0),
        );
    }

    public function covers(int $amountMinor): bool
    {
        if ($amountMinor < $this->fromMinor) {
            return false;
        }

        return $this->toMinor === null || $amountMinor <= $this->toMinor;
    }

    public function overlaps(self $other): bool
    {
        $thisEnd = $this->toMinor ?? PHP_INT_MAX;
        $otherEnd = $other->toMinor ?? PHP_INT_MAX;

        return $this->fromMinor <= $otherEnd && $other->fromMinor <= $thisEnd;
    }

    public function toArray(): array
    {
        return [
            'from_minor' => $this->fromMinor,
            'to_minor' => $this->toMinor,
            'fee_minor' => $this->feeMinor,
        ];
    }
}
