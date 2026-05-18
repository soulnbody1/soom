<?php

namespace App\Notifications;

use App\Models\Auction;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\BroadcastMessage;

class AuctionWonNotification extends Notification
{
    use Queueable;

    public function __construct(protected Auction $auction) {}

    public function via($notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toArray($notifiable): array
    {
        $unreadCount = $notifiable->unreadNotifications()->count() + 1;
        
        return [
            'type' => 'auction_won',
            'auction_id' => $this->auction->id,
            'title' => '🎉 مبروك! فزت بالمزاد',
            'message' => "لقد فزت بالمزاد: {$this->auction->title}",
            'amount' => (float) $this->auction->current_bid,
            'image' => $this->auction->images->first()?->image_path,
            'advertiser_name' => $this->auction->user->name,
            'advertiser_phone' => $this->auction->user->phone,
            'created_at' => now()->toDateTimeString(),
            'unread_count' => $unreadCount,
        ];
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'type' => 'auction_won',
            'auction_id' => $this->auction->id,
            'title' => '🎉 مبروك! فزت بالمزاد',
            'message' => "لقد فزت بالمزاد: {$this->auction->title}",
            'amount' => (float) $this->auction->current_bid,
        ]);
    }
}
