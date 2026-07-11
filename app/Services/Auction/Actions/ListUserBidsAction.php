<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Repositories\Auction\Queries\AuctionBidHistoryQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListUserBidsAction
{
    public function __construct(
        private readonly AuctionBidHistoryQuery $query,
    ) {}

    public function execute(int $bidderId, int $perPage): LengthAwarePaginator
    {
        return $this->query->paginateByUser($bidderId, $perPage);
    }
}
