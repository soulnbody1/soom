<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Repositories\Auction\Queries\AdminAuctionQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListAdminAuctionsAction
{
    public function __construct(
        private readonly AdminAuctionQuery $query,
    ) {}

    public function execute(int $perPage): LengthAwarePaginator
    {
        return $this->query->paginate($perPage);
    }
}
