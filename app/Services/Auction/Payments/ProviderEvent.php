<?php

declare(strict_types=1);

namespace App\Services\Auction\Payments;

final readonly class ProviderEvent
{
    public function __construct(
        public string $eventId,
        public string $eventType,
        public string $providerTransactionId,
        public bool $signatureVerified,
        public array $payload = [],
    ) {}
}
