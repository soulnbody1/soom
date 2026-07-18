<?php

declare(strict_types=1);

namespace App\Events\Auction;

use App\Services\Auction\Support\AuctionNotificationCatalog;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Safe realtime update on the public per-auction channel. The payload must
 * only ever contain data that is already publicly visible for the auction
 * (anonymous bidders, public ids, amounts, times).
 */
final class AuctionRealtimeEvent implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        private readonly string $auctionPublicId,
        private readonly string $eventType,
        private readonly array $payload,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel(AuctionNotificationCatalog::auctionChannelName($this->auctionPublicId));
    }

    public function broadcastAs(): string
    {
        return $this->eventType;
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
