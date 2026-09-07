<?php

declare(strict_types=1);

namespace App\Services\Message\Actions;

use App\Events\UnreadCountUpdated;
use App\Models\Message;
use App\Repositories\Message\Queries\UnreadConversationCounter;

final class MarkConversationReadAction
{
    public function __construct(private readonly UnreadConversationCounter $unread) {}

    public function execute(int $userId, int $partnerId): int
    {
        $updated = Message::where('sender_id', $partnerId)
            ->where('receiver_id', $userId)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        if ($updated > 0) {
            event(new UnreadCountUpdated($userId, $this->unread->forUser($userId)));
        }

        return $updated;
    }
}
