<?php

declare(strict_types=1);

namespace App\Jobs\Message;

use App\Events\ConversationUpdated;
use App\Events\UnreadCountUpdated;
use App\Repositories\Message\Queries\ConversationThreadsQuery;
use App\Repositories\Message\Queries\UnreadConversationCounter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class BroadcastConversationUpdate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [5, 30, 120];

    public int $timeout = 30;

    public function __construct(
        public readonly int $viewerId,
        public readonly int $partnerId,
        public readonly bool $withUnreadCount = false,
        public readonly string $eventName = ConversationUpdated::UPDATED,
    ) {}

    public function handle(ConversationThreadsQuery $threads, UnreadConversationCounter $unread): void
    {
        $conversation = $threads->forPartner($this->viewerId, $this->partnerId);

        if ($conversation !== null) {
            event(new ConversationUpdated($conversation, $this->viewerId, $this->eventName));
        }

        if ($this->withUnreadCount) {
            event(new UnreadCountUpdated($this->viewerId, $unread->forUser($this->viewerId)));
        }
    }
}
