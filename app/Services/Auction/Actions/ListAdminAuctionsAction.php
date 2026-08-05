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

    public function execute(array $filters, int $perPage, bool $withContentReview = false): LengthAwarePaginator
    {
        return $this->query->paginate($filters, $perPage, $withContentReview);
    }
}
