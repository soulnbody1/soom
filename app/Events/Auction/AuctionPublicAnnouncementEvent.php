<?php

declare(strict_types=1);

namespace App\Events\Auction;

use App\Services\Auction\Support\AuctionNotificationCatalog;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

final class AuctionPublicAnnouncementEvent implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        private readonly string $announcementType,
        private readonly array $payload,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel(AuctionNotificationCatalog::PUBLIC_CHANNEL);
    }

    public function broadcastAs(): string
    {
        return 'auction.announcement';
    }

    public function broadcastWith(): array
    {
        return ['type' => $this->announcementType, ...$this->payload];
    }
}
