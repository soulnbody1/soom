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

    public function firstOrCreate(array $uniqueAttributes, array $defaults): AuctionWinnerReassignment
    {
        return AuctionWinnerReassignment::firstOrCreate($uniqueAttributes, $defaults);
    }

    public function save(AuctionWinnerReassignment $reassignment): void
    {
        $reassignment->save();
    }
}
