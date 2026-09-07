<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Message;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class MessagePolicy
{
    public const RECALL_WINDOW_SECONDS = 120;

    public function delete(User $user, Message $message): Response
    {
        return $this->involves($user, $message)
            ? Response::allow()
            : Response::deny('لا يمكنك حذف رسائل لا تخصك.');
    }

    public function recall(User $user, Message $message): bool
    {
        return $message->sender_id === $user->id
            && $message->created_at !== null
            && $message->created_at->greaterThanOrEqualTo(now()->subSeconds(self::RECALL_WINDOW_SECONDS));
    }

    private function involves(User $user, Message $message): bool
    {
        return $message->sender_id === $user->id || $message->receiver_id === $user->id;
    }
}
