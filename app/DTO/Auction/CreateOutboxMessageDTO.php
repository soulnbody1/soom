<?php

declare(strict_types=1);

namespace App\DTO\Auction;

use App\Domain\Auction\Enums\OutboxStatus;
use App\DTO\Auction\Contracts\PersistenceDTO;
use Carbon\CarbonInterface;

final readonly class CreateOutboxMessageDTO extends BaseAuctionDTO implements PersistenceDTO
{
    public function __construct(
        public string $eventId,
        public string $topic,
        public string $eventType,
        public string $aggregateType,
        public int $aggregateId,
        public array $payload,
        public OutboxStatus $status,
        public CarbonInterface $availableAt,
        public ?CarbonInterface $nextRetryAt = null,
    ) {}

    public function toPersistenceArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'topic' => $this->topic,
            'event_type' => $this->eventType,
            'aggregate_type' => $this->aggregateType,
            'aggregate_id' => $this->aggregateId,
            'payload' => $this->payload,
            'status' => $this->status,
            'available_at' => $this->availableAt,
            'next_retry_at' => $this->nextRetryAt,
        ];
    }

    public function toArray(): array
    {
        return $this->toPersistenceArray();
    }
}
