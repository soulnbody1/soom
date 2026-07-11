<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Repositories\Auction\Queries\SellerAuctionQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListSellerAuctionsAction
{
    public function __construct(
        private readonly SellerAuctionQuery $query,
    ) {}

    public function execute(int $sellerId, int $perPage): LengthAwarePaginator
    {
        return $this->query->paginate($sellerId, $perPage);
    }
}
