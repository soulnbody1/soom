<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Models\Auction\Auction;
use App\Repositories\Auction\Queries\ViewerAuctionQuery;

final class LoadAuctionDetailsAction
{
    public function __construct(
        private readonly ViewerAuctionQuery $query,
    ) {}

    public function execute(Auction $auction, ?int $viewerId = null): Auction
    {
        return $this->query->loadDetails($auction, $viewerId);
    }
}
