<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

final class AuctionOutboxNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $eventId,
        private readonly string $eventType,
        private readonly array $data,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'event_id' => $this->eventId,
            'event_type' => $this->eventType,
            ...$this->data,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        $unreadCount = $notifiable->unreadNotifications()->count() + 1;

        return new BroadcastMessage([
            'event_id' => $this->eventId,
            'event_type' => $this->eventType,
            ...$this->data,
            'created_at' => now()->toDateTimeString(),
            'unread_count' => $unreadCount,
        ]);
    }
}
