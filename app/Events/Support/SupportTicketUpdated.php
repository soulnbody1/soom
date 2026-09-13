<?php

declare(strict_types=1);

namespace App\Events\Support;

use App\Models\Support\SupportTicket;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class SupportTicketUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly SupportTicket $ticket) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('support.ticket.'.$this->ticket->public_id),
            new PrivateChannel('support.queue'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'support.ticket.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'ticket_id' => $this->ticket->public_id,
            'status' => $this->ticket->status->value,
            'priority' => $this->ticket->priority->value,
            'assigned_to' => $this->ticket->assigned_to,
            'version' => $this->ticket->version,
            'updated_at' => $this->ticket->updated_at?->toIso8601String(),
        ];
    }
}
