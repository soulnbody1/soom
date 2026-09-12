<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

abstract class OutboxEventNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected readonly string $eventId,
        protected readonly string $eventType,
        protected readonly array $data,
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
        return new BroadcastMessage([
            ...$this->toArray($notifiable),
            'created_at' => now()->toDateTimeString(),
            'unread_count' => $notifiable->unreadNotifications()->count(),
        ]);
    }
}
