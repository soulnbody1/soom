<?php

declare(strict_types=1);

namespace App\Repositories\Auction\Queries;

use App\Models\Auction\Auction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class SellerAuctionQuery
{
    private const RELATIONS = [
        'media',
        'metric',
        'currentLeadingBid',
        'settlement',
    ];

    /**
     * Paginate seller's auctions.
     * Replaces ListSellerAuctionsAction query.
     */
    public function paginate(int $sellerId, int $perPage): LengthAwarePaginator
    {
        return Auction::with(self::RELATIONS)
            ->where('seller_id', $sellerId)
            ->latest('id')
            ->paginate($perPage);
    }
}
