<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Repositories\Auction\Queries\ViewerAuctionQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListSellerAuctionsAction
{
    public function __construct(
        private readonly ViewerAuctionQuery $query,
    ) {}

    public function execute(int $sellerId, array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->query->paginateForSeller($sellerId, $filters, $perPage);
    }
}
