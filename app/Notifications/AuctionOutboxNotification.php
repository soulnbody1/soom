<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
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
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'event_id' => $this->eventId,
            'event_type' => $this->eventType,
            ...$this->data,
        ];
    }
}
