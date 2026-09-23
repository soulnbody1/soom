<?php

declare(strict_types=1);

namespace App\Repositories\User\Queries;

use App\Models\Auction\AuctionActivityLog;
use App\Models\User;
use App\Services\Market\MarketQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class UserActivityQuery
{
    public function __construct(private readonly MarketQuery $markets) {}

    public function paginate(User $user, array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->base($user)
            ->when($filters['event_type'] ?? null, fn (Builder $query, $value) => $query->where('event_type', $value))
            ->when($filters['auction_id'] ?? null, fn (Builder $query, $value) => $query->whereHas('auction', fn (Builder $inner) => $inner->where('public_id', $value)))
            ->paginate($perPage)
            ->withQueryString();
    }

    public function recent(User $user, int $limit): Collection
    {
        return $this->base($user)->limit($limit)->get();
    }

    public function eventTypes(User $user): array
    {
        return $this->markets->table('auction_activity_logs')
            ->where('user_id', $user->id)
            ->distinct()
            ->orderBy('event_type')
            ->pluck('event_type')
            ->all();
    }

    private function base(User $user): Builder
    {
        return AuctionActivityLog::query()
            ->select(['id', 'public_id', 'auction_id', 'event_type', 'actor_type', 'metadata', 'created_at'])
            ->where('user_id', $user->id)
            ->with('auction:id,public_id,title')
            ->latest('id');
    }
}
