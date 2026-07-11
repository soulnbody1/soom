<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Models\Auction\Auction;
use App\Repositories\Auction\Queries\PublicAuctionQuery;

final class LoadAuctionDetailsAction
{
    public function __construct(
        private readonly PublicAuctionQuery $query,
    ) {}

    public function execute(Auction $auction): Auction
    {
        return $this->query->loadDetails($auction);
    }
}
