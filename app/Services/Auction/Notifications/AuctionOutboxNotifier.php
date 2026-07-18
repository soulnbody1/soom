<?php

declare(strict_types=1);

namespace App\Services\Auction\Notifications;

use App\Models\Auction\Auction;
use App\Models\Auction\OutboxMessage;
use App\Services\Auction\Support\AuctionNotificationCatalog;

final class AuctionOutboxNotifier
{
    public function __construct(
        private readonly PersonalNotificationSender $personal,
        private readonly AuctionRealtimeBroadcaster $realtime,
        private readonly AuctionAnnouncementBroadcaster $announcements,
    ) {}

    public function notify(OutboxMessage $message): void
    {
        if (! AuctionNotificationCatalog::supports($message->event_type)) {
            throw new \RuntimeException("Unsupported auction outbox event: {$message->event_type}");
        }

        if ($message->aggregate_type !== Auction::class) {
            throw new \RuntimeException("Unsupported auction outbox aggregate: {$message->aggregate_type}");
        }

        $auction = Auction::with([
            'seller',
            'settlement.winner',
            'participants.user',
            'currentLeadingBid',
            'metric',
            'category',
            'media',
        ])->findOrFail($message->aggregate_id);

        if (AuctionNotificationCatalog::hasPersonal($message->event_type)) {
            $this->personal->send($message, $auction);
        }

        if (AuctionNotificationCatalog::hasRealtime($message->event_type)) {
            $this->realtime->broadcast($message, $auction);
        }

        if (AuctionNotificationCatalog::hasPublicAnnouncement($message->event_type)) {
            $this->announcements->broadcast($message, $auction);
        }
    }
}
