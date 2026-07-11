<?php

declare(strict_types=1);

namespace App\Repositories\Auction\Queries;

use App\Models\Auction\Auction;
use App\Models\Auction\AuctionBid;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class AuctionBidHistoryQuery
{
    /**
     * Paginate bids for a specific auction.
     * Replaces ListAuctionBidsAction query.
     */
    public function paginateByAuction(Auction $auction, int $perPage): LengthAwarePaginator
    {
        return AuctionBid::with(['bidder', 'auction'])
            ->where('auction_id', $auction->id)
            ->orderByDesc('amount_minor')
            ->orderBy('sequence_number')
            ->paginate($perPage);
    }

    /**
     * Paginate a user's bid history across all auctions.
     * Replaces ListUserBidsAction query.
     */
    public function paginateByUser(int $bidderId, int $perPage): LengthAwarePaginator
    {
        return AuctionBid::with(['auction.media', 'bidder'])
            ->where('bidder_id', $bidderId)
            ->latest('id')
            ->paginate($perPage);
    }
}
