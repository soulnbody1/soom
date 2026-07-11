<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Models\Auction\Auction;
use App\Repositories\Auction\Queries\AuctionBidHistoryQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListAuctionBidsAction
{
    public function __construct(
        private readonly AuctionBidHistoryQuery $query,
    ) {}

    public function execute(Auction $auction, int $perPage): LengthAwarePaginator
    {
        return $this->query->paginateByAuction($auction, $perPage);
    }
}
