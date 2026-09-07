<?php

declare(strict_types=1);

namespace App\Repositories\Message\Queries\Concerns;

use Closure;
use Illuminate\Support\Facades\DB;

trait FiltersDeletedMessages
{
    private function hiddenFrom(int $userId, string $messagesTable = 'messages'): Closure
    {
        return function ($query) use ($userId, $messagesTable): void {
            $query->select(DB::raw(1))
                ->from('message_deletions')
                ->whereColumn('message_deletions.message_id', $messagesTable.'.id')
                ->where('message_deletions.user_id', $userId);
        };
    }
}
