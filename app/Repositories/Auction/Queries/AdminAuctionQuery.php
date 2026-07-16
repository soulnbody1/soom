<?php

declare(strict_types=1);

namespace App\Repositories\Auction\Queries;

use App\Models\Auction\Auction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class AdminAuctionQuery
{
    private const RELATIONS = [
        'media',
        'category',
        'seller',
        'metric',
        'currentLeadingBid',
        'winningBid',
        'settlement',
    ];

    private const SORTABLE = ['created_at', 'starts_at', 'ends_at'];

    /**
     * Paginate all auctions for admin dashboard with server-side filters.
     * Replaces ListAdminAuctionsAction query.
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        return Auction::with(self::RELATIONS)
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['category_id'] ?? null, fn (Builder $query, $categoryId) => $query->where('category_id', (int) $categoryId))
            ->when($filters['seller_id'] ?? null, fn (Builder $query, $sellerId) => $query->where('seller_id', (int) $sellerId))
            ->when($filters['q'] ?? null, function (Builder $query, string $q): void {
                $query->where(function (Builder $match) use ($q): void {
                    $match->where('title', 'like', '%'.$q.'%')
                        ->orWhere('public_id', $q);
                });
            })
            ->when($filters['starts_from'] ?? null, fn (Builder $query, string $from) => $query->where('starts_at', '>=', $from))
            ->when($filters['starts_to'] ?? null, fn (Builder $query, string $to) => $query->where('starts_at', '<=', $to))
            ->when($filters['ends_from'] ?? null, fn (Builder $query, string $from) => $query->where('ends_at', '>=', $from))
            ->when($filters['ends_to'] ?? null, fn (Builder $query, string $to) => $query->where('ends_at', '<=', $to))
            ->when(
                in_array($filters['sort'] ?? null, self::SORTABLE, true),
                fn (Builder $query) => $query->orderBy(
                    (string) $filters['sort'],
                    ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc'
                ),
                fn (Builder $query) => $query->latest('id')
            )
            ->paginate($perPage)
            ->withQueryString();
    }
}
