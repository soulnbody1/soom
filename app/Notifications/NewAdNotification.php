<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class NewAdNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected string $adId,
        protected string $adTitle,
        protected int $categoryId,
        protected array $unreadCounts = [],
    ) {}

    public function via($notifiable)
    {
        return ['database', 'broadcast'];
    }

    public function toArray($notifiable)
    {
        return [
            'ad_id' => $this->adId,
            'title' => $this->adTitle,
            'category_id' => $this->categoryId,
            'message' => '📢 إعلان جديد تم إضافته في الفئة التي تهتم بها',
            'created_at' => now()->toDateTimeString(),
        ];
    }

    public function toBroadcast($notifiable)
    {
        return new BroadcastMessage([
            ...$this->toArray($notifiable),
            'unread_count' => $this->unreadCountFor($notifiable),
        ]);
    }

    private function unreadCountFor($notifiable): int
    {
        return $this->unreadCounts[(int) $notifiable->id]
            ?? $notifiable->unreadNotifications()->count();
    }
}
