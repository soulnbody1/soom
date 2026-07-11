<?php

declare(strict_types=1);

namespace App\Events\Auction;

final readonly class AuctionOutboxEvent
{
    public function __construct(
        public string $eventId,
        public string $topic,
        public string $eventType,
        public string $aggregateType,
        public int $aggregateId,
        public array $payload
    ) {}
}
