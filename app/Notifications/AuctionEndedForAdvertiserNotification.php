<?php

namespace App\Notifications;

use App\Models\Auction;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\BroadcastMessage;

class AuctionEndedForAdvertiserNotification extends Notification
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
        
        if ($this->auction->winner) {
            $title = '✅ المزاد انتهى بفائز';
            $message = "انتهى مزادك: {$this->auction->title} بفائز بقيمة {$this->auction->current_bid} د.أ";
        } else {
            $title = '⚠️ المزاد انتهى بدون فائز';
            $message = "انتهى مزادك: {$this->auction->title} بدون وصول للحد الأدنى";
        }
        
        return [
            'type' => 'auction_ended',
            'auction_id' => $this->auction->id,
            'title' => $title,
            'message' => $message,
            'has_winner' => (bool) $this->auction->winner,
            'final_price' => $this->auction->winner ? (float) $this->auction->current_bid : null,
            'winner_name' => $this->auction->winner?->name,
            'winner_phone' => $this->auction->winner?->phone,
            'created_at' => now()->toDateTimeString(),
            'unread_count' => $unreadCount,
        ];
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        if ($this->auction->winner) {
            $message = "انتهى مزادك بفائز: {$this->auction->winner->name}";
        } else {
            $message = "انتهى مزادك بدون فائز";
        }
        
        return new BroadcastMessage([
            'type' => 'auction_ended',
            'auction_id' => $this->auction->id,
            'title' => '✅ المزاد انتهى',
            'message' => $message,
        ]);
    }
}
