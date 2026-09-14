<?php

declare(strict_types=1);

namespace App\Repositories\Message\Queries;

use App\Models\Message;
use App\Repositories\Message\Queries\Concerns\FiltersDeletedMessages;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class ConversationMessagesQuery
{
    use FiltersDeletedMessages;

    public const PER_PAGE = 20;

    public function paginate(int $userId, int $partnerId, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        return $this->between($userId, $partnerId)
            ->select(['id', 'sender_id', 'receiver_id', 'content', 'attachment_path', 'attachment_type', 'is_read', 'ad_id', 'created_at'])
            ->with(['ad:id,public_id,title,description,price,deleted_at', 'ad.images' => fn ($query) => $query->select('ad_id', 'image_path')->limit(1)])
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function lastVisible(int $userId, int $partnerId): ?Message
    {
        return $this->between($userId, $partnerId)->orderByDesc('id')->first();
    }

    private function between(int $userId, int $partnerId): Builder
    {
        return Message::query()
            ->where(function (Builder $query) use ($userId, $partnerId): void {
                $query
                    ->where(fn (Builder $side) => $side->where('sender_id', $userId)->where('receiver_id', $partnerId))
                    ->orWhere(fn (Builder $side) => $side->where('sender_id', $partnerId)->where('receiver_id', $userId));
            })
            ->whereNotExists($this->hiddenFrom($userId));
    }
}
