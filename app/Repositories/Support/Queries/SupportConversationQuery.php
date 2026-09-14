<?php

declare(strict_types=1);

namespace App\Repositories\Support\Queries;

use App\Domain\Support\Enums\SupportMessageVisibility;
use App\Models\Support\SupportMessage;
use App\Models\Support\SupportTicket;

final class SupportConversationQuery
{
    public function execute(SupportTicket $ticket, ?string $after, int $limit, bool $includeInternal): array
    {
        $cursorQuery = SupportMessage::query()->where('ticket_id', $ticket->id);
        $messageQuery = SupportMessage::query()->with('author')->where('ticket_id', $ticket->id);

        if (! $includeInternal) {
            $cursorQuery->where('visibility', SupportMessageVisibility::Public->value);
            $messageQuery->where('visibility', SupportMessageVisibility::Public->value);
        }

        $cursor = $after === null ? null : $cursorQuery->where('public_id', $after)->firstOrFail();

        if ($cursor !== null) {
            $rows = $messageQuery->where('id', '>', $cursor->id)->orderBy('id')->limit($limit + 1)->get();

            return [
                'messages' => $rows->take($limit)->values(),
                'has_more_before' => false,
                'has_more_after' => $rows->count() > $limit,
            ];
        }

        $rows = $messageQuery->orderByDesc('id')->limit($limit + 1)->get();

        return [
            'messages' => $rows->take($limit)->reverse()->values(),
            'has_more_before' => $rows->count() > $limit,
            'has_more_after' => false,
        ];
    }
}
