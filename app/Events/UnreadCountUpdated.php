<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

class UnreadCountUpdated implements ShouldBroadcastNow
{
    use SerializesModels;

    public int $userId;
    public int $unreadCount;

    public function __construct(int $userId, int $unreadCount)
    {
        $this->userId = $userId;
        $this->unreadCount = $unreadCount;
    }

    public function broadcastOn()
    {
        return new PrivateChannel('conversations.' . $this->userId);
    }

    public function broadcastAs()
    {
        return 'unread.count.updated';
    }

    public function broadcastWith()
    {
        return [
            'unread_count' => $this->unreadCount,
        ];
    }
}
