<?php

declare(strict_types=1);

namespace App\Services\Auction\Notifications;

use App\Models\Auction\Auction;
use App\Models\Auction\OutboxMessage;
use App\Models\User;
use App\Notifications\AuctionOutboxNotification;
use App\Services\Notification\PushDispatcher;

final class PersonalNotificationSender
{
    public function __construct(
        private readonly PersonalDeliveryResolver $deliveries,
        private readonly NotificationValueFormatter $format,
        private readonly PushDispatcher $push,
    ) {}

    public function send(OutboxMessage $message, Auction $auction): void
    {
        foreach ($this->deliveries->resolve($message, $auction) as $delivery) {
            $this->deliver($message, $auction, $delivery);
        }
    }

    private function deliver(OutboxMessage $message, Auction $auction, array $delivery): void
    {
        /** @var User $user */
        $user = $delivery['user'];

        if ($this->alreadyNotified($user, (string) $message->event_id)) {
            return;
        }

        $title = $this->format->text("{$delivery['key']}.title", $delivery['params']);
        $body = $this->format->text("{$delivery['key']}.body", $delivery['params']);
        $marketCode = strtolower((string) $auction->market?->code);
        $url = $auction->market?->webUrl('auctions/'.$auction->public_id);

        $user->notify(new AuctionOutboxNotification(
            (string) $message->event_id,
            $message->event_type,
            [
                'auction_id' => $auction->public_id,
                'market_code' => $marketCode,
                'url' => $url,
                'auction_title' => $auction->title,
                'screen' => $delivery['screen'],
                'title' => $title,
                'message' => $body,
                ...$delivery['extra'],
            ],
        ));

        $this->push->toUser((int) $user->id, $title, $body, [
            'event_type' => $message->event_type,
            'auction_id' => $auction->public_id,
            'market_code' => $marketCode,
            'url' => $url,
            'screen' => $delivery['screen'],
        ]);
    }

    private function alreadyNotified(User $user, string $eventId): bool
    {
        return $user->notifications()
            ->where('type', AuctionOutboxNotification::class)
            ->where('data->event_id', $eventId)
            ->exists();
    }
}
