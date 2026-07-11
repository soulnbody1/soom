<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\Auction;
use App\Services\Auction\Support\AuctionStateMachine;
use App\Services\Auction\Support\AuctionTransaction;

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
            throw new AuctionException(__('auction.errors.rejection_reason_required'));
        }

        return $this->transaction->run(function () use ($auction, $adminId, $reason): Auction {
            $auction = Auction::whereKey($auction->id)->lockForUpdate()->firstOrFail();

            return $this->stateMachine->transition($auction, AuctionStatus::Rejected, $adminId, 'admin', $reason);
        });
    }
}
