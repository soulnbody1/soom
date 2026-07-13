<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Repositories\Auction\AuctionRepository;
use App\Services\Auction\Support\AuctionStateMachine;
use App\Services\Auction\Support\AuctionTransaction;

final class StartDueAuctionsAction
{
    public function __construct(
        private readonly AuctionStateMachine $stateMachine,
        private readonly AuctionRepository $auctions,
        private readonly AuctionTransaction $transaction,
    ) {}

    public function execute(int $limit = 100): int
    {
        $count = 0;

        $this->auctions->findDueForStart()
            ->take($limit)
            ->each(function ($auction) use (&$count): void {
                $started = $this->transaction->run(function () use ($auction): bool {
                    $locked = $this->auctions->lockForStateChange($auction->id);

                    if (
                        $locked->status !== AuctionStatus::Scheduled
                        || ! $locked->starts_at
                        || $locked->starts_at->isFuture()
                    ) {
                        return false;
                    }

                    $this->stateMachine->transition($locked, AuctionStatus::Live, null, 'system', 'scheduled start time reached');

                    return true;
                });

                if ($started) {
                    $count++;
                }
            });

        return $count;
    }
}
