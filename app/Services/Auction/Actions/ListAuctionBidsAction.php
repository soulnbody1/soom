<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Models\Auction\Auction;
use App\Models\Auction\AuctionBid;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListAuctionBidsAction
{
    public function execute(Auction $auction, int $perPage): LengthAwarePaginator
    {
        return AuctionBid::with(['bidder', 'auction'])
            ->where('auction_id', $auction->id)
            ->orderByDesc('amount_minor')
            ->orderBy('sequence_number')
            ->paginate($perPage);
    }
}
