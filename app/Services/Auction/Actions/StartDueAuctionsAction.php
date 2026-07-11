<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Repositories\Auction\AuctionRepository;
use App\Services\Auction\Support\AuctionStateMachine;

final class StartDueAuctionsAction
{
    public function __construct(
        private readonly AuctionStateMachine $stateMachine,
        private readonly AuctionRepository $auctions,
    ) {}

    public function execute(int $limit = 100): int
    {
        $count = 0;

        $this->auctions->findDueForStart()
            ->take($limit)
            ->each(function ($auction) use (&$count): void {
                $this->stateMachine->transition($auction, AuctionStatus::Live, null, 'system', 'scheduled start time reached');
                $count++;
            });

        return $count;
    }
}
