<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

class ConversationUpdated implements ShouldBroadcastNow
{
    use SerializesModels;

    public $conversation;
    public $userId;

    public function __construct($conversation, $userId)
    {
        $this->conversation = $conversation;
        $this->userId = $userId;
    }

    public function broadcastOn()
    {
        return new PrivateChannel('conversations.' . $this->userId);
    }

    public function broadcastAs()
    {
        return 'conversation.updated';
    }

    public function broadcastWith()
    {
        return [
            'conversation' => $this->conversation,
        ];
    }
}
