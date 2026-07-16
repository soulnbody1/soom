<?php

declare(strict_types=1);

namespace App\Repositories\Auction;

use App\Models\Auction\AuctionDispute;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class AuctionDisputeRepository
{
    /**
     * Paginate disputes across auctions for the admin queue.
     * Open disputes first when no status filter is applied.
     * Used by AuctionDisputeController::index.
     */
    public function paginateForAdmin(array $filters, int $perPage): LengthAwarePaginator
    {
        return AuctionDispute::with([
            'auction:id,public_id,title,status',
            'settlement:id,public_id',
            'opener:id,name',
            'resolver:id,name',
        ])
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['auction_id'] ?? null, function (Builder $query, string $auctionId): void {
                if (ctype_digit($auctionId)) {
                    $query->where('auction_id', (int) $auctionId);

                    return;
                }

                $query->whereHas('auction', fn (Builder $auction) => $auction->where('public_id', $auctionId));
            })
            ->when(
                ! ($filters['status'] ?? null),
                fn (Builder $query) => $query->orderByRaw("case when status = 'open' then 0 else 1 end")
            )
            ->latest('opened_at')
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Create or find an open dispute (idempotent).
     * Used by OpenAuctionDisputeAction.
     */
    public function firstOrCreateOpenDispute(array $uniqueAttributes, array $defaults): AuctionDispute
    {
        return AuctionDispute::firstOrCreate($uniqueAttributes, $defaults);
    }

    /**
     * Lock a dispute for resolution.
     * Used by ResolveAuctionDisputeAction.
     */
    public function lockForResolution(int $disputeId): AuctionDispute
    {
        return AuctionDispute::whereKey($disputeId)->lockForUpdate()->firstOrFail();
    }

    /**
     * Save dispute model after in-memory changes.
     */
    public function save(AuctionDispute $dispute): void
    {
        $dispute->save();
    }
}
