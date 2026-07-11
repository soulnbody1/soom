<?php

declare(strict_types=1);

namespace App\Application\Auction\Actions;

use App\Application\Auction\Services\AuctionStateMachine;
use App\Application\Auction\Services\AuctionTransaction;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\Auction;

final class ReviewAuctionAction
{
    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionStateMachine $stateMachine
    ) {}

    public function approve(Auction $auction, int $adminId, string $reason): Auction
    {
        return $this->transaction->run(function () use ($auction, $adminId, $reason): Auction {
            $auction = Auction::whereKey($auction->id)->lockForUpdate()->firstOrFail();

            return $this->stateMachine->transition(
                $auction,
                AuctionStatus::AwaitingSellerDeposit,
                $adminId,
                'admin',
                $reason
            );
        });
    }

    public function reject(Auction $auction, int $adminId, string $reason): Auction
    {
        if (trim($reason) === '') {
            throw new AuctionException('Rejection reason is required.');
        }

        return $this->transaction->run(function () use ($auction, $adminId, $reason): Auction {
            $auction = Auction::whereKey($auction->id)->lockForUpdate()->firstOrFail();

            return $this->stateMachine->transition($auction, AuctionStatus::Rejected, $adminId, 'admin', $reason);
        });
    }
}
