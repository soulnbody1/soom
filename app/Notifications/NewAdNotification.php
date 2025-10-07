<?php

namespace App\Notifications;

use App\Models\Ad;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\BroadcastMessage;


class NewAdNotification extends Notification
{
    use Queueable;

    protected $ad;

    public function __construct(Ad $ad)
    {
        $this->ad = $ad;
    }

    public function via($notifiable)
    {
        return ['database', 'broadcast'];
    }

    public function toArray($notifiable)
    {
        $unreadCount = $notifiable->unreadNotifications()->count() + 1;
        return [
            'ad_id' => $this->ad->id,
            'title' => $this->ad->title,
            'category_id' => $this->ad->category_id,
            'message' => '📢 إعلان جديد تم إضافته في الفئة التي تهتم بها',
            'created_at' => now()->toDateTimeString(),
            'unread_count' => $unreadCount,

        ];
    }


    public function toBroadcast($notifiable)
    {
        $unreadCount = $notifiable->unreadNotifications()->count() + 1;
        return new BroadcastMessage([
            'ad_id' => $this->ad->id,
            'title' => $this->ad->title,
            'category_id' => $this->ad->category_id,
            'message' => '📢 إعلان جديد تم إضافته في الفئة التي تهتم بها',
            'created_at' => now()->toDateTimeString(),
            'unread_count' => $unreadCount,
        ]);
    }
}
