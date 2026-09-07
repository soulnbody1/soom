<?php

declare(strict_types=1);

namespace App\Services\Message\Actions;

use App\Events\ConversationUpdated;
use App\Events\MessageSent;
use App\Events\UnreadCountUpdated;
use App\Jobs\SendFcmNotification;
use App\Models\Message;
use App\Models\User;
use App\Repositories\Message\Queries\ConversationThreadsQuery;
use App\Repositories\Message\Queries\UnreadConversationCounter;
use App\Services\Message\Support\ChatAttachmentStorage;
use App\Services\Message\Support\ChatPresence;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

final class SendMessageAction
{
    public function __construct(
        private readonly ChatAttachmentStorage $attachments,
        private readonly ChatPresence $presence,
        private readonly ConversationThreadsQuery $threads,
        private readonly UnreadConversationCounter $unread,
    ) {}

    public function execute(User $sender, User $receiver, array $data): Message
    {
        $attachment = $data['file'] instanceof UploadedFile
            ? $this->attachments->store($data['file'])
            : ['attachment_path' => null, 'attachment_type' => null];

        try {
            $message = DB::transaction(fn (): Message => Message::create([
                'sender_id' => $sender->id,
                'receiver_id' => $receiver->id,
                'ad_id' => $data['ad_id'],
                'content' => $data['content'],
                'attachment_path' => $attachment['attachment_path'],
                'attachment_type' => $attachment['attachment_type'],
                'is_read' => false,
            ]));
        } catch (Throwable $exception) {
            $this->attachments->discard($attachment['attachment_path']);

            throw $exception;
        }

        $isRead = $this->markReadIfBothPresent($message, $sender->id, $receiver->id);

        $this->broadcast($message, $data['temporary_code'], $isRead, $sender->id, $receiver->id);
        $this->notify($sender, $receiver, $message);

        return $message;
    }

    private function markReadIfBothPresent(Message $message, int $senderId, int $receiverId): bool
    {
        if (! $this->presence->bothPresent($senderId, $receiverId)) {
            return false;
        }

        Message::where('sender_id', $senderId)
            ->where('receiver_id', $receiverId)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        $message->setAttribute('is_read', true);

        return true;
    }

    private function broadcast(Message $message, ?string $temporaryCode, bool $isRead, int $senderId, int $receiverId): void
    {
        event(new MessageSent($message->load('ad.images'), $temporaryCode, $isRead));

        event(new ConversationUpdated($this->threads->forPartner($receiverId, $senderId), $receiverId));
        event(new ConversationUpdated($this->threads->forPartner($senderId, $receiverId), $senderId));

        event(new UnreadCountUpdated($receiverId, $this->unread->forUser($receiverId)));
    }

    private function notify(User $sender, User $receiver, Message $message): void
    {
        if (! $receiver->fcm_token) {
            return;
        }

        SendFcmNotification::dispatchSync(
            $receiver->fcm_token,
            '📢 رسالة جديدة من '.$sender->name,
            (string) $message->content,
            [
                'id' => $message->id,
                'name' => $sender->name,
                'sender_id' => $message->sender_id,
                'receiver_id' => $message->receiver_id,
                'content' => $message->content,
                'attachment_url' => $message->attachmentUrl(),
                'attachment_type' => $message->attachment_type,
                'created_at' => $message->created_at->toDateTimeString(),
            ]
        );
    }
}
