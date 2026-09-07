<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

class ConversationUpdated implements ShouldBroadcastNow
{
    use SerializesModels;

    public const UPDATED = 'conversation.updated';

    public const DELETED = 'Message.delete';

    public $conversation;
    public $userId;
    public string $eventName;

    public function __construct($conversation, $userId, string $eventName = self::UPDATED)
    {
        $this->conversation = $conversation;
        $this->userId = $userId;
        $this->eventName = $eventName;
    }

    public function broadcastOn()
    {
        return new PrivateChannel('conversations.' . $this->userId);
    }

    public function broadcastAs()
    {
        return $this->eventName;
    }

    public function broadcastWith()
    {
        return [
            'conversation' => $this->conversation,
        ];
    }
}
