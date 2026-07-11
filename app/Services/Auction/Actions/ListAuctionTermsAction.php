<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Repositories\Auction\Queries\AuctionTermsQuery;
use Illuminate\Support\Collection;

final class ListAuctionTermsAction
{
    public function __construct(
        private readonly AuctionTermsQuery $query,
    ) {}

    public function execute(): Collection
    {
        return $this->query->list();
    }
}
