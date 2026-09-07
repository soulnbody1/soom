<?php

declare(strict_types=1);

namespace App\Services\Message\Actions;

use App\Events\MessageSent;
use App\Jobs\Message\BroadcastConversationUpdate;
use App\Jobs\SendFcmNotification;
use App\Models\Message;
use App\Models\User;
use App\Services\Message\Support\ChatAttachmentStorage;
use App\Services\Message\Support\ChatPresence;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SendMessageAction
{
    public function __construct(
        private readonly ChatAttachmentStorage $attachments,
        private readonly ChatPresence $presence,
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
        try {
            $bothPresent = $this->presence->bothPresent($senderId, $receiverId);
        } catch (Throwable $exception) {
            Log::warning('Chat presence check failed: '.$exception->getMessage(), [
                'message_id' => $message->id,
            ]);

            return false;
        }

        if (! $bothPresent) {
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
        try {
            event(new MessageSent($message->load('ad.images'), $temporaryCode, $isRead));
        } catch (Throwable $exception) {
            Log::warning('Chat realtime broadcast failed: '.$exception->getMessage(), [
                'message_id' => $message->id,
            ]);
        }

        BroadcastConversationUpdate::dispatch($receiverId, $senderId, true);
        BroadcastConversationUpdate::dispatch($senderId, $receiverId);
    }

    private function notify(User $sender, User $receiver, Message $message): void
    {
        if (! $receiver->fcm_token) {
            return;
        }

        try {
            SendFcmNotification::dispatch(
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
        } catch (Throwable $exception) {
            Log::warning('Chat push notification was not queued: '.$exception->getMessage(), [
                'message_id' => $message->id,
            ]);
        }
    }
}
