<?php

declare(strict_types=1);

namespace App\Repositories\Message\Queries;

use App\Repositories\Message\Queries\Concerns\FiltersDeletedMessages;
use Illuminate\Support\Facades\DB;

final class UnreadConversationCounter
{
    use FiltersDeletedMessages;

    public function forUser(int $userId): int
    {
        return DB::table('messages')
            ->where('receiver_id', $userId)
            ->where('is_read', false)
            ->whereColumn('sender_id', '!=', 'receiver_id')
            ->whereNotExists($this->hiddenFrom($userId))
            ->distinct()
            ->count('sender_id');
    }
}
