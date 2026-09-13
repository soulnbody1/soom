<?php

declare(strict_types=1);

namespace App\Events\Support;

use App\Domain\Support\Enums\SupportMessageVisibility;
use App\Models\Support\SupportMessage;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class SupportMessageCreated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly SupportMessage $message) {}

    public function broadcastOn(): array
    {
        if ($this->message->visibility === SupportMessageVisibility::Internal) {
            return [new PrivateChannel('support.queue')];
        }

        return [
            new PrivateChannel('support.ticket.'.$this->message->ticket->public_id),
            new PrivateChannel('support.queue'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'support.message.created';
    }

    public function broadcastWith(): array
    {
        return [
            'ticket_id' => $this->message->ticket->public_id,
            'message_id' => $this->message->public_id,
            'author_type' => $this->message->author_type->value,
            'visibility' => $this->message->visibility->value,
            'created_at' => $this->message->created_at?->toIso8601String(),
        ];
    }
}
