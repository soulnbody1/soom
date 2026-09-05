<?php

declare(strict_types=1);

namespace App\Services\Auction\Payments;

use Carbon\CarbonImmutable;

final readonly class PaymentIntent
{
    public function __construct(
        public string $merchantReference,
        public int $amountMinor,
        public string $currencyCode,
        public string $purpose,
        public string $returnUrl,
        public array $metadata = [],
        public ?int $payerId = null,
        public ?CarbonImmutable $payableUntil = null,
    ) {}
}
