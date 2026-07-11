<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Models\Auction\AuctionBid;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListUserBidsAction
{
    public function execute(int $bidderId, int $perPage): LengthAwarePaginator
    {
        return AuctionBid::with(['auction.media', 'bidder'])
            ->where('bidder_id', $bidderId)
            ->latest('id')
            ->paginate($perPage);
    }
}
