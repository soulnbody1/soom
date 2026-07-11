<?php

declare(strict_types=1);

namespace App\Listeners\Auction;

use App\Events\Auction\AuctionOutboxEvent;
use App\Models\Auction\Auction;
use App\Repositories\Auction\AuctionAuditRepository;

final class RecordAuctionOutboxConsumption
{
    public function __construct(private readonly AuctionAuditRepository $audit) {}

    public function handle(AuctionOutboxEvent $event): bool
    {
        if ($event->aggregateType !== Auction::class) {
            return false;
        }

        $this->audit->logActivity(
            auctionId: $event->aggregateId,
            actorId: null,
            eventType: 'auction.outbox_consumed',
            actorType: 'system',
            metadata: [
                'event_id' => $event->eventId,
                'event_type' => $event->eventType,
                'topic' => $event->topic,
                'payload' => $event->payload,
            ],
        );

        return true;
    }
}
