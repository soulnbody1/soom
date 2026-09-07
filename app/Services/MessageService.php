<?php

namespace App\Services;

use App\Events\ConversationUpdated;
use App\Events\MessageSent;
use App\Events\UnreadCountUpdated;
use App\Jobs\SendFcmNotification;
use App\Repositories\Message\Queries\ConversationMessagesQuery;
use App\Repositories\Message\Queries\ConversationThreadsQuery;
use App\Repositories\Message\Queries\UnreadConversationCounter;
use App\Repositories\MessageRepository;
use App\Services\Message\Actions\DeleteMessagesAction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\UploadedFile;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Pusher\Pusher;

class MessageService
{
    public function __construct(
        private readonly MessageRepository $messageRepo,
        private readonly ConversationThreadsQuery $threads,
        private readonly ConversationMessagesQuery $messages,
        private readonly UnreadConversationCounter $unread,
        private readonly DeleteMessagesAction $deleteMessages,
    ) {}

    public function sendMessage(array $data): Message
    {
        $sender = auth('sanctum')->user();
        $data['sender_id'] = $sender->id;

        $receiver = User::find($data['receiver_id']);
        if (!$receiver) {
            throw new \Exception('المستخدم غير موجود');
        }

        if (!empty($data['file']) && $data['file'] instanceof UploadedFile) {
            $data = array_merge($data, $this->handleAttachment($data['file']));
        }

        $message = $this->messageRepo->create($data);
        $updatedCount = $this->markMessagesAsReadIfBothOnline($sender->id, $receiver->id);

        $is_read = $updatedCount > 0;
        $this->broadcastEvents($message, $data['TemporaryCode'] ?? null, $is_read, $receiver->id, $sender->id);

        if ($receiver->fcm_token) {
            $this->sendFcmNotification($receiver, $sender, $message);
        }

        $message['is_read'] = $is_read;
        return $message;
    }

    private function handleAttachment(UploadedFile $file): array
    {
        $mime = $file->getMimeType();

        $maxSizes = [
            'image' => 5 * 1024 * 1024,
            'pdf' => 10 * 1024 * 1024,
            'audio' => 7 * 1024 * 1024,
            'video' => 15 * 1024 * 1024,
            'zip' => 8 * 1024 * 1024,
        ];

        $category = match (true) {
            str_starts_with($mime, 'image/') => 'image',
            $mime === 'application/pdf' => 'pdf',
            str_starts_with($mime, 'audio/') => 'audio',
            str_starts_with($mime, 'video/') => 'video',
            str_contains($mime, 'zip') => 'zip',
            default => null,
        };

        if (!$category || !isset($maxSizes[$category]) || $file->getSize() > $maxSizes[$category]) {
            throw new \Exception('نوع الملف أو حجمه غير مسموح');
        }

        return $this->messageRepo->storeAttachment($file);
    }

    private function broadcastEvents(Message $message, ?string $temporaryCode, bool $is_read,  int $receiverId, int $senderId): void
    {
        event(new MessageSent($message->load('ad.images'), $temporaryCode, $is_read));

        event(new ConversationUpdated($this->threads->forPartner($receiverId, $senderId), $receiverId));
        event(new ConversationUpdated($this->threads->forPartner($senderId, $receiverId), $senderId));

        $unreadCount =  $this->getTotalUnreadConversationsCount($receiverId);
        event(new UnreadCountUpdated($receiverId, $unreadCount));
    }

    private function sendFcmNotification(User $receiver, User $sender, Message $message): void
    {
        SendFcmNotification::dispatchSync(
            $receiver->fcm_token,
            '📢 رسالة جديدة من ' . $sender->name,
            $message->content,
            [
                'id' => $message->id,
                'name' => $sender->name,
                'sender_id' => $message->sender_id,
                'receiver_id' => $message->receiver_id,
                'content' => $message->content,
                'attachment_url' => $message->attachmentUrl() ?? null,
                'attachment_type' => $message->attachment_type ?? null,
                'created_at' => $message->created_at->toDateTimeString(),
            ]
        );
    }

    public function getConversationWith(int $userId)
    {
        $viewerId = (int) Auth::id();

        event(new UnreadCountUpdated($viewerId, $this->getTotalUnreadConversationsCount($viewerId)));

        return $this->messages->paginate($viewerId, $userId);
    }

    public function getTotalUnreadConversationsCount(int $userId): int
    {
        return $this->unread->forUser($userId);
    }

    public function getUserConversations(?string $search = null, int $page = 1, int $perPage = ConversationThreadsQuery::PER_PAGE)
    {
        return $this->threads->paginate((int) Auth::id(), $page, $perPage, $search);
    }

    public function delete(array $data): bool
    {
        $this->deleteMessages->execute(
            (int) Auth::id(),
            (int) $data['user_id'],
            $data['message_ids'] ?? null
        );

        return true;
    }

    function markMessagesAsReadIfBothOnline(int $userId, int $receiverId): int
    {
        $channelName = 'presence-chat.' . min($userId, $receiverId) . '.' . max($userId, $receiverId);

        $pusher = new Pusher(
            config('broadcasting.connections.pusher.key'),
            config('broadcasting.connections.pusher.secret'),
            config('broadcasting.connections.pusher.app_id'),
            config('broadcasting.connections.pusher.options')
        );

        try {
            $response = $pusher->get("/channels/{$channelName}/users");
            $users = (array) ($response->users ?? []);
            $onlineUserIds = collect($users)->pluck('id')->map(fn($id) => (int) $id)->all();

            if (in_array($userId, $onlineUserIds) && in_array($receiverId, $onlineUserIds)) {
                $updated = Message::where('sender_id', $userId)
                    ->where('receiver_id', $receiverId)
                    ->where('is_read', false)
                    ->update(['is_read' => true]);

                Log::info("🔄 تم تحديث {$updated} رسالة كمقروءة.");
                return $updated;
            } else {
                Log::info("❌ أحد المستخدمين غير متصل: {$userId} أو {$receiverId}");
            }
        } catch (\Exception $e) {
            Log::error('Pusher presence check failed: ' . $e->getMessage(), ['userId' => $userId]);
        }
        return 0;
    }
}
