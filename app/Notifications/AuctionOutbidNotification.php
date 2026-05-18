<?php

namespace App\Notifications;

use App\Models\Auction;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\BroadcastMessage;

class AuctionOutbidNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected Auction $auction,
        protected float $newBidAmount
    ) {}

    public function via($notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toArray($notifiable): array
    {
        $unreadCount = $notifiable->unreadNotifications()->count() + 1;
        
        return [
            'type' => 'outbid',
            'auction_id' => $this->auction->id,
            'title' => '⚠️ تم تجاوز مزايدتك',
            'message' => "تم تجاوز مزايدتك في: {$this->auction->title}",
            'your_bid' => null,
            'new_bid' => (float) $this->newBidAmount,
            'time_remaining' => $this->auction->getTimeRemainingFormatted(),
            'image' => $this->auction->images->first()?->image_path,
            'created_at' => now()->toDateTimeString(),
            'unread_count' => $unreadCount,
        ];
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'type' => 'outbid',
            'auction_id' => $this->auction->id,
            'title' => '⚠️ تم تجاوز مزايدتك',
            'message' => "تم تجاوز مزايدتك في: {$this->auction->title}",
            'new_bid' => (float) $this->newBidAmount,
        ]);
    }
}
