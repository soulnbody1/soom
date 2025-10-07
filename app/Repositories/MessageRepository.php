<?php

namespace App\Repositories;

use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;




class MessageRepository
{
    public function create(array $data): Message
    {
        return Message::create($data);
    }

    public function storeAttachment($file): array
    {
        $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeName = Str::slug($originalName);
        $extension = $file->getClientOriginalExtension();
        $filename = $safeName . '_' . time() . '_' . Str::random(6) . '.' . $extension;
        $path = $file->storeAs('chat_files', $filename, 'spaces');
        return [
            'attachment_path' => $path,
            'attachment_type' => $file->getMimeType(),
        ];
    }

    public function getConversation($userId1, $userId2)
    {
        $currentUserId = $userId1;
        return Message::with('ad.images')
        ->where(function ($q) use ($userId1, $userId2) {
            $q->where('sender_id', $userId1)
                ->where('receiver_id', $userId2);
        })->orWhere(function ($q) use ($userId1, $userId2) {
            $q->where('sender_id', $userId2)
                ->where('receiver_id', $userId1);
        })->whereNotIn('id', function ($q) use ($currentUserId) {
            $q->select('message_id')
                ->from('message_deletions')
                ->where('user_id', $currentUserId);
        })->orderBy('created_at', 'desc')
            ->paginate(20);
    }

    public function getUserConversations($userId, $perPage = 20, $search = null): LengthAwarePaginator
    {
        $subQuery = $this->getUserMessagesSubQuery($userId);
        $conversations = DB::table(DB::raw("({$subQuery->toSql()}) as sub"))
            ->mergeBindings($subQuery)
            ->select(
                DB::raw('other_user_id as user_id'),
                DB::raw('MAX(id) as last_message_id'),
                DB::raw('SUM(CASE WHEN receiver_id = ? AND is_read = 0 THEN 1 ELSE 0 END) as unread_count')
            )
            ->addBinding($userId)
            ->groupBy('other_user_id')
            ->orderByDesc('last_message_id')
            ->get();

        // Load last messages and user data
        $lastMessages = $this->getLastMessages($conversations->pluck('last_message_id')->all());
        $users = $this->getUsers($conversations->pluck('user_id')->all());

        // Apply search filter
        if ($search) {
            $users = $users->filter(function ($user) use ($search) {
                return str_contains(strtolower($user->name), strtolower($search));
            });
        }

        // Filter conversations based on the filtered users
        $filtered = $conversations->filter(function ($conversation) use ($users) {
            return isset($users[$conversation->user_id]);
        })->values();

        // Transform
        $transformed = $filtered->map(function ($conversation) use ($lastMessages, $users, $userId) {
            return $this->transformConversation($conversation, $lastMessages, $users, $userId);
        })->filter()->values();

        return new LengthAwarePaginator(
            $transformed,
            $transformed->count(),
            $perPage,
            request()->get('page', 1),
            [
                'path' => request()->url(),
                'query' => request()->query(),
            ]
        );
    }

    protected function getUserMessagesSubQuery($userId)
    {
        return DB::table('messages')
            ->leftJoin('message_deletions', function ($join) use ($userId) {
                $join->on('messages.id', '=', 'message_deletions.message_id')
                    ->where('message_deletions.user_id', '=', $userId);
            })
            ->whereNull('message_deletions.id')
            ->where(function ($q) use ($userId) {
                $q->where('sender_id', $userId)
                    ->orWhere('receiver_id', $userId);
            })
            ->whereRaw('NOT (sender_id = ? AND receiver_id = ?)', [$userId, $userId])
            ->select(
                'messages.id',
                'messages.content',
                'messages.sender_id',
                'messages.receiver_id',
                'messages.is_read',
                DB::raw('IF(sender_id = ?, receiver_id, sender_id) as other_user_id')
            )
            ->addBinding($userId); // for the IF() condition
    }

    protected function getLastMessages(array $ids)
    {
        return Message::with(['ad.images' => function ($q) {
            $q->select('ad_id', 'image_path')->limit(1);
        }])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');
    }

    protected function getUsers(array $ids)
    {
        return User::whereIn('id', $ids)->get()->keyBy('id');
    }

    protected function transformConversation($conversation, $lastMessages, $users, $userId)
    {
        $message = $lastMessages[$conversation->last_message_id] ?? null;
        $user = $users[$conversation->user_id] ?? null;

        if (!$message || !$user) {
            return null;
        }

        return (object)[
            'user' => (object)[
                'id'   => $user->id,
                'name' => $user->name,
                'logo' => $user->logo,
            ],
            'last_message' => (object)[
                'id'        => $message->id,
                'content'   => $message->content,
                'from_me'   => $message->sender_id == $userId,
                'created_at' => $message->created_at->format('Y-m-d H:i'),
                'ad' => $message->ad ? (object)[
                    'id'          => $message->ad->id,
                    'title'       => $message->ad->title,
                    'description' => $message->ad->description,
                    'price'       => $message->ad->price,
                    'image'       => $message->ad->images->first()?->image_path,
                ] : null,
            ],
            'unread_count' => $conversation->unread_count,
        ];
    }

    public function getTotalUnreadConversationsCount(int $userId): int
    {
        return DB::table('messages')
            ->where('receiver_id', $userId)
            ->where('is_read', false)
            ->whereNotIn('id', function ($query) use ($userId) {
                $query->select('message_id')
                    ->from('message_deletions')
                    ->where('user_id', $userId);
            })
            ->select(DB::raw("COUNT(DISTINCT IF(sender_id = {$userId}, receiver_id, sender_id)) as total"))
            ->value('total');
    }

    public function getLastVisibleMessage(int $userId, int $otherUserId, ?int $exceptMessageId = null): ?Message
    {
        return Message::where(function ($q) use ($userId, $otherUserId) {
            $q->where('sender_id', $userId)->where('receiver_id', $otherUserId);
        })->orWhere(function ($q) use ($userId, $otherUserId) {
            $q->where('sender_id', $otherUserId)->where('receiver_id', $userId);
        })
            ->when($exceptMessageId, function ($query) use ($exceptMessageId) {
                $query->where('id', '!=', $exceptMessageId);
            })
            ->whereNotIn('id', function ($q) use ($userId) {
                $q->select('message_id')
                    ->from('message_deletions')
                    ->where('user_id', $userId);
            })
            ->orderByDesc('created_at')
            ->first();
    }

    public function getConversationObject(int $authUserId, int $otherUserId)
    {
        return $this->getUserConversations($authUserId)->firstWhere('user.id', $otherUserId);
    }
}
