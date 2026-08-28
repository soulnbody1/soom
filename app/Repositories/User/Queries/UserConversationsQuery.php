<?php

declare(strict_types=1);

namespace App\Repositories\User\Queries;

use App\Models\Message;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class UserConversationsQuery
{
    public function threadUnion(int $userId): Builder
    {
        $sent = DB::table('messages')
            ->selectRaw('receiver_id as partner_id, id as message_id, 0 as inbound, is_read')
            ->where('sender_id', $userId)
            ->whereColumn('sender_id', '!=', 'receiver_id');

        return DB::table('messages')
            ->selectRaw('sender_id as partner_id, id as message_id, 1 as inbound, is_read')
            ->where('receiver_id', $userId)
            ->whereColumn('sender_id', '!=', 'receiver_id')
            ->unionAll($sent);
    }

    public function countThreads(int $userId): int
    {
        return DB::query()
            ->fromSub($this->threadUnion($userId), 'threads')
            ->distinct()
            ->count('partner_id');
    }

    public function paginateThreads(User $user, int $perPage, int $page): LengthAwarePaginator
    {
        $total = $this->countThreads($user->id);

        $rows = $total === 0 ? collect() : collect(
            DB::query()
                ->fromSub($this->threadUnion($user->id), 'threads')
                ->selectRaw('partner_id, MAX(message_id) as last_message_id, COUNT(*) as messages_count, SUM(CASE WHEN inbound = 1 AND is_read = 0 THEN 1 ELSE 0 END) as unread_count')
                ->groupBy('partner_id')
                ->orderByDesc('last_message_id')
                ->forPage($page, $perPage)
                ->get()
        );

        return new Paginator(
            $this->hydrateThreads($user->id, $rows),
            $total,
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    public function paginateMessages(User $user, User $partner, int $perPage): LengthAwarePaginator
    {
        return Message::query()
            ->select(['id', 'sender_id', 'receiver_id', 'content', 'attachment_path', 'attachment_type', 'is_read', 'ad_id', 'created_at'])
            ->where(fn ($query) => $query->where('sender_id', $user->id)->where('receiver_id', $partner->id))
            ->orWhere(fn ($query) => $query->where('sender_id', $partner->id)->where('receiver_id', $user->id))
            ->with('ad:id,title,price')
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    private function hydrateThreads(int $userId, Collection $rows): Collection
    {
        if ($rows->isEmpty()) {
            return collect();
        }

        $messages = Message::query()
            ->select(['id', 'sender_id', 'receiver_id', 'content', 'attachment_type', 'is_read', 'ad_id', 'created_at'])
            ->whereIn('id', $rows->pluck('last_message_id')->all())
            ->with('ad:id,title')
            ->get()
            ->keyBy('id');

        $partners = User::withTrashed()
            ->select(['id', 'name', 'phone', 'logo', 'deleted_at'])
            ->whereIn('id', $rows->pluck('partner_id')->all())
            ->get()
            ->keyBy('id');

        return $rows
            ->map(function (object $row) use ($messages, $partners, $userId): ?array {
                $partner = $partners->get($row->partner_id);
                $message = $messages->get($row->last_message_id);

                if ($partner === null || $message === null) {
                    return null;
                }

                return [
                    'partner' => $partner,
                    'last_message' => $message,
                    'messages_count' => (int) $row->messages_count,
                    'unread_count' => (int) $row->unread_count,
                    'viewer_id' => $userId,
                ];
            })
            ->filter()
            ->values();
    }
}
