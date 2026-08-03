<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Repositories\Auction\Queries\ViewerAuctionQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListPublicAuctionsAction
{
    public function __construct(
        private readonly ViewerAuctionQuery $query,
    ) {}

    public function execute(array $filters, ?int $viewerId, int $perPage): LengthAwarePaginator
    {
        return $this->query->paginate($filters, $viewerId, $perPage);
    }
}
