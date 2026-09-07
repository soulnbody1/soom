<?php

declare(strict_types=1);

namespace App\Repositories\Message\Queries;

use App\Models\Message;
use App\Models\User;
use App\Repositories\Message\Queries\Concerns\FiltersDeletedMessages;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ConversationThreadsQuery
{
    use FiltersDeletedMessages;

    public const PER_PAGE = 20;

    public const MAX_PER_PAGE = 100;

    public function paginate(int $userId, int $page = 1, int $perPage = self::PER_PAGE, ?string $search = null): LengthAwarePaginator
    {
        $total = $this->count($userId, $search);

        $rows = $total === 0
            ? new Collection
            : new Collection($this->rollup($userId, $search)->forPage($page, $perPage)->get());

        return new Paginator(
            $this->hydrate($userId, $rows),
            $total,
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    public function forPartner(int $userId, int $partnerId): ?object
    {
        $row = $this->rollup($userId, null, $partnerId)->first();

        if ($row === null) {
            return null;
        }

        return $this->hydrate($userId, new Collection([$row]))->first();
    }

    public function count(int $userId, ?string $search = null): int
    {
        return DB::query()
            ->fromSub($this->threads($userId), 'threads')
            ->when($search !== null, fn (Builder $query) => $this->applySearch($query, $search))
            ->distinct()
            ->count('threads.partner_id');
    }

    private function rollup(int $userId, ?string $search = null, ?int $partnerId = null): Builder
    {
        return DB::query()
            ->fromSub($this->threads($userId), 'threads')
            ->when($search !== null, fn (Builder $query) => $this->applySearch($query, $search))
            ->when($partnerId !== null, fn (Builder $query) => $query->where('threads.partner_id', $partnerId))
            ->selectRaw('threads.partner_id, MAX(threads.message_id) as last_message_id, SUM(CASE WHEN threads.inbound = 1 AND threads.is_read = 0 THEN 1 ELSE 0 END) as unread_count')
            ->groupBy('threads.partner_id')
            ->orderByDesc('last_message_id');
    }

    private function threads(int $userId): Builder
    {
        $sent = DB::table('messages')
            ->selectRaw('receiver_id as partner_id, id as message_id, 0 as inbound, is_read')
            ->where('sender_id', $userId)
            ->whereColumn('sender_id', '!=', 'receiver_id')
            ->whereNotExists($this->hiddenFrom($userId));

        return DB::table('messages')
            ->selectRaw('sender_id as partner_id, id as message_id, 1 as inbound, is_read')
            ->where('receiver_id', $userId)
            ->whereColumn('sender_id', '!=', 'receiver_id')
            ->whereNotExists($this->hiddenFrom($userId))
            ->unionAll($sent);
    }

    private function applySearch(Builder $query, string $search): Builder
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search);

        return $query
            ->join('users', 'users.id', '=', 'threads.partner_id')
            ->where('users.name', 'like', '%'.$escaped.'%');
    }

    private function hydrate(int $userId, Collection $rows): Collection
    {
        if ($rows->isEmpty()) {
            return new Collection;
        }

        $messages = Message::query()
            ->select(['id', 'sender_id', 'content', 'ad_id', 'created_at'])
            ->with(['ad:id,title,description,price', 'ad.images' => fn ($query) => $query->select('ad_id', 'image_path')->limit(1)])
            ->whereIn('id', $rows->pluck('last_message_id')->all())
            ->get()
            ->keyBy('id');

        $partners = User::withTrashed()
            ->select(['id', 'name', 'logo', 'deleted_at'])
            ->whereIn('id', $rows->pluck('partner_id')->all())
            ->get()
            ->keyBy('id');

        return $rows
            ->map(function (object $row) use ($messages, $partners, $userId): ?object {
                $message = $messages->get($row->last_message_id);
                $partner = $partners->get($row->partner_id);

                if ($message === null || $partner === null) {
                    return null;
                }

                return (object) [
                    'user' => (object) [
                        'id' => $partner->id,
                        'name' => $partner->name,
                        'logo' => $partner->logo,
                    ],
                    'last_message' => (object) [
                        'id' => $message->id,
                        'content' => $message->content,
                        'from_me' => $message->sender_id == $userId,
                        'created_at' => $message->created_at->format('Y-m-d H:i'),
                        'ad' => $message->ad ? (object) [
                            'id' => $message->ad->id,
                            'title' => $message->ad->title,
                            'description' => $message->ad->description,
                            'price' => $message->ad->price,
                            'image' => $message->ad->images->first()?->image_path,
                        ] : null,
                    ],
                    'unread_count' => $row->unread_count,
                ];
            })
            ->filter()
            ->values();
    }
}
