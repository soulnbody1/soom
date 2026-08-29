<?php

declare(strict_types=1);

namespace App\Services\Auction\Payments;

final readonly class RefundCommand
{
    public function __construct(
        public string $providerTransactionId,
        public string $merchantReference,
        public int $amountMinor,
        public string $currencyCode,
        public string $reason,
    ) {}
}
