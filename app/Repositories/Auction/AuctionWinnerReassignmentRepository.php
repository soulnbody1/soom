<?php

declare(strict_types=1);

namespace App\Repositories\Auction;

use App\Models\Auction\AuctionWinnerReassignment;

final class AuctionWinnerReassignmentRepository
{
    public function create(array $attributes): AuctionWinnerReassignment
    {
        return AuctionWinnerReassignment::create($attributes);
    }
}
