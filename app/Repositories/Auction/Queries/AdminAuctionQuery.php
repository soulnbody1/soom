<?php

declare(strict_types=1);

namespace App\Repositories\Auction\Queries;

use App\Models\Auction\Auction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

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

    /**
     * Paginate all auctions for admin dashboard.
     * Replaces ListAdminAuctionsAction query.
     */
    public function paginate(int $perPage): LengthAwarePaginator
    {
        return Auction::with(self::RELATIONS)
            ->latest('id')
            ->paginate($perPage);
    }
}
