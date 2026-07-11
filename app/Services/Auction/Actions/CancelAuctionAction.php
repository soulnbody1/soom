<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Models\Auction\Auction;
use App\Repositories\Auction\AuctionRepository;
use App\Services\Auction\Support\AuctionStateMachine;
use App\Services\Auction\Support\AuctionTransaction;

final class CancelAuctionAction
{
    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionStateMachine $stateMachine,
        private readonly AuctionRepository $auctions,
    ) {}

    public function execute(Auction $auction, int $actorId, string $actorType, string $reason): Auction
    {
        return $this->transaction->run(fn () => $this->stateMachine->transition(
            $this->auctions->lockForStateChange($auction->id),
            AuctionStatus::Cancelled,
            $actorId,
            $actorType,
            $reason
        ));
    }
}
