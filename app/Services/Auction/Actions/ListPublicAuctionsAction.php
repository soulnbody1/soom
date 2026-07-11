<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Repositories\Auction\Queries\PublicAuctionQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListPublicAuctionsAction
{
    public function __construct(
        private readonly PublicAuctionQuery $query,
    ) {}

    public function execute(?int $categoryId, int $perPage): LengthAwarePaginator
    {
        return $this->query->paginate($categoryId, $perPage);
    }
}
