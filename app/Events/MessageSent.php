<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public bool $deleteWhenMissingModels = true;

    public $message;

    public $TemporaryCode;

    public $is_read;

    public function __construct(Message $message, $TemporaryCode, $is_read)
    {
        $this->message = $message;
        $this->TemporaryCode = $TemporaryCode;
        $this->is_read = $is_read;

    }

    public function broadcastOn(): Channel
    {
        return new PresenceChannel('chat.'.min($this->message->sender_id, $this->message->receiver_id).'.'.max($this->message->sender_id, $this->message->receiver_id));

    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'sender_id' => $this->message->sender_id,
            'receiver_id' => (int) $this->message->receiver_id,
            'TemporaryCode' => $this->TemporaryCode,
            'content' => $this->message->content,
            'attachment_url' => $this->message->attachmentUrl() ?? null,
            'attachment_type' => $this->message->attachment_type ?? null,
            'is_read' => $this->is_read,
            'ad_id' => $this->message->ad?->public_id,
            'ad' => $this->message->ad && ! $this->message->ad->trashed() ? [
                'id' => $this->message->ad->public_id,
                'title' => $this->message->ad->title,
                'description' => $this->message->ad->description,
                'price' => $this->message->ad->price,
                'image' => $this->message->ad->images->first()?->image_path,
            ] : null,
            'created_at' => $this->message->created_at->toDateTimeString(),
        ];
    }
}
