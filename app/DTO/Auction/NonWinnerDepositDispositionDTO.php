<?php

declare(strict_types=1);

namespace App\DTO\Auction;

final readonly class NonWinnerDepositDispositionDTO
{
    public function __construct(
        public int $depositId,
        public int $userId,
        public string $disposition,
        public string $reason,
        public ?int $refundId = null,
    ) {}
}
