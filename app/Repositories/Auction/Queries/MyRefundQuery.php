<?php

declare(strict_types=1);

namespace App\Repositories\Auction\Queries;

use App\Models\Auction\RefundTransaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class MyRefundQuery
{
    public function paginate(int $userId, array $filters, int $perPage): LengthAwarePaginator
    {
        return RefundTransaction::query()
            ->where('user_id', $userId)
            ->with([
                'market:id,code,web_host',
                'auction:id,market_id,public_id,title,currency_code,status',
                'auction.market:id,code,web_host',
            ])
            ->when($filters['status'] ?? null, fn (Builder $q, $value) => $q->where('status', $value))
            ->when($filters['search'] ?? null, function (Builder $q, $value): void {
                $term = trim((string) $value);
                $q->whereHas('auction', fn (Builder $inner) => $inner
                    ->where('title', 'like', '%'.$term.'%')
                    ->orWhere('public_id', $term));
            })
            ->orderBy('id', ($filters['sort'] ?? 'latest') === 'oldest' ? 'asc' : 'desc')
            ->paginate($perPage)
            ->withQueryString();
    }
}
