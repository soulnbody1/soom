<?php

declare(strict_types=1);

namespace App\Listeners\Auction;

use App\Events\Auction\AuctionOutboxEvent;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionActivityLog;
use App\Repositories\Auction\AuctionAuditRepository;

final class RecordAuctionOutboxConsumption
{
    private const SUPPORTED_EVENTS = [
        'auction.status_changed' => 'status_changed',
        'auction.finalized' => 'winner_selected',
        'auction.bid_accepted' => 'bid_placed',
        'auction.winner_defaulted' => 'winner_defaulted',
        'auction.alternative_winner_selected' => 'alternative_winner_selected',
        'auction.seller_handover_confirmed' => 'seller_handover_confirmed',
        'auction.dispute_opened' => 'dispute_opened',
        'auction.dispute_resolved' => 'dispute_resolved',
        'auction.cancelled' => 'auction_cancelled',
        'auction.payment_submitted' => 'payment_submitted',
        'auction.payment_approved' => 'payment_approved',
        'auction.payment_rejected' => 'payment_rejected',
        'auction.refund_processing' => 'refund_processing',
        'auction.refund_succeeded' => 'refund_succeeded',
        'auction.refund_failed' => 'refund_failed',
        'auction.winner_receipt_confirmed' => 'winner_receipt_confirmed',
        'auction.completed' => 'auction_completed',
    ];

    public function __construct(private readonly AuctionAuditRepository $audit) {}

    public function handle(AuctionOutboxEvent $event): bool
    {
        if ($event->aggregateType !== Auction::class) {
            return false;
        }

        $consumer = self::SUPPORTED_EVENTS[$event->eventType] ?? null;
        if ($consumer === null) {
            return false;
        }

        $eventType = "auction.outbox_consumer.{$consumer}";
        if (AuctionActivityLog::where('auction_id', $event->aggregateId)
            ->where('event_type', $eventType)
            ->where('metadata->event_id', $event->eventId)
            ->exists()) {
            return true;
        }

        $this->audit->logActivity(
            auctionId: $event->aggregateId,
            actorId: null,
            eventType: $eventType,
            actorType: 'system',
            metadata: [
                'event_id' => $event->eventId,
                'event_type' => $event->eventType,
                'topic' => $event->topic,
                'consumer' => $consumer,
                'payload' => $event->payload,
            ],
        );

        return true;
    }
}
