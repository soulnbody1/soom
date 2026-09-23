<?php

declare(strict_types=1);

namespace App\Services\Auction\Notifications;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Events\Auction\AuctionPublicAnnouncementEvent;
use App\Jobs\Auction\SendAuctionAnnouncementJob;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionMedia;
use App\Models\Auction\OutboxMessage;
use App\Services\Auction\Support\AuctionNotificationCatalog;
use Illuminate\Support\Facades\Storage;

final class AuctionAnnouncementBroadcaster
{
    public function __construct(
        private readonly NotificationValueFormatter $format,
    ) {}

    public function broadcast(OutboxMessage $message, Auction $auction): void
    {
        $type = $this->announcementType($message, $auction);

        if ($type === null) {
            return;
        }

        $currency = (string) $auction->currency_code;
        $params = [
            'auction' => (string) $auction->title,
            'starts_at' => $this->format->dateTime($auction->starts_at),
            'amount' => $this->format->money((int) $auction->starting_amount_minor, $currency),
            'currency' => $currency,
        ];

        $title = $this->format->text("announcements.{$type}.title", $params);
        $body = $this->format->text("announcements.{$type}.body", $params);

        broadcast(new AuctionPublicAnnouncementEvent($type, [
            'auction_id' => $auction->public_id,
            'market_code' => strtolower((string) $auction->market?->code),
            'url' => $auction->market?->webUrl('auctions/'.$auction->public_id),
            'title' => (string) $auction->title,
            'category' => $auction->category?->name,
            'image_url' => $this->primaryImageUrl($auction),
            'starting_amount' => $this->format->money((int) $auction->starting_amount_minor, $currency),
            'currency' => $currency,
            'starts_at' => $auction->starts_at?->toIso8601String(),
            'ends_at' => $auction->ends_at?->toIso8601String(),
            'screen' => AuctionNotificationCatalog::SCREEN_AUCTION_DETAILS,
            'announcement_title' => $title,
            'announcement_message' => $body,
        ]));

        if ($type === 'published') {
            SendAuctionAnnouncementJob::dispatch($auction->id, $auction->market_id, $type, $title, $body);
        }
    }

    private function announcementType(OutboxMessage $message, Auction $auction): ?string
    {
        $payload = $message->payload ?? [];

        return match ($message->event_type) {
            'auction.status_changed' => match ((string) ($payload['to'] ?? '')) {
                AuctionStatus::Scheduled->value => 'published',
                AuctionStatus::Live->value => 'started',
                default => null,
            },
            'auction.cancelled' => $auction->published_at && ! $auction->started_at
                ? 'cancelled'
                : null,
            default => null,
        };
    }

    private function primaryImageUrl(Auction $auction): ?string
    {
        $image = $auction->media->firstWhere('is_primary', true) ?? $auction->media->first();

        return $image instanceof AuctionMedia
            ? Storage::disk($image->disk)->url($image->path)
            : null;
    }
}
