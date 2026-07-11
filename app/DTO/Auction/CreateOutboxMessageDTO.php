<?php

declare(strict_types=1);

namespace App\DTO\Auction;

use App\Domain\Auction\Enums\OutboxStatus;
use App\DTO\Auction\Contracts\PersistenceDTO;
use Carbon\CarbonInterface;

final readonly class CreateOutboxMessageDTO extends BaseAuctionDTO implements PersistenceDTO
{
    public function __construct(
        public string $event_id,
        public string $topic,
        public string $event_type,
        public string $aggregate_type,
        public int $aggregate_id,
        public array $payload,
        public OutboxStatus $status,
        public CarbonInterface $available_at,
    ) {}

    public function toPersistenceArray(): array
    {
        return [
            'event_id' => $this->event_id,
            'topic' => $this->topic,
            'event_type' => $this->event_type,
            'aggregate_type' => $this->aggregate_type,
            'aggregate_id' => $this->aggregate_id,
            'payload' => $this->payload,
            'status' => $this->status,
            'available_at' => $this->available_at,
        ];
    }

    public function toArray(): array
    {
        return $this->toPersistenceArray();
    }
}
