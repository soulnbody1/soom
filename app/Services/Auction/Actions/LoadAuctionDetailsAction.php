<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Models\Auction\Auction;

final class LoadAuctionDetailsAction
{
    public function execute(Auction $auction): Auction
    {
        return $auction->load([
            'media',
            'category',
            'country',
            'state',
            'city',
            'metric',
            'currentLeadingBid.bidder',
            'winningBid.bidder',
            'settlement',
        ]);
    }
}
