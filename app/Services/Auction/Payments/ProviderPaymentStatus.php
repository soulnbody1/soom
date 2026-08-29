<?php

declare(strict_types=1);

namespace App\Services\Auction\Payments;

use App\Domain\Auction\Enums\PaymentTransactionStatus;

final readonly class ProviderPaymentStatus
{
    public function __construct(
        public PaymentTransactionStatus $status,
        public string $providerTransactionId,
        public ?int $amountMinor = null,
        public ?string $currencyCode = null,
        public ?string $failureCode = null,
        public ?int $providerFeeMinor = null,
        public ?string $settlementReference = null,
        public ?string $merchantReference = null,
        public array $payload = [],
    ) {}
}
