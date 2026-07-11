<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\Auction;
use App\Repositories\Auction\AuctionRepository;
use App\Services\Auction\Support\AuctionStateMachine;
use App\Services\Auction\Support\AuctionTransaction;

final class ReviewAuctionAction
{
    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionStateMachine $stateMachine,
        private readonly AuctionRepository $auctions,
    ) {}

    public function approve(Auction $auction, int $adminId, string $reason): Auction
    {
        return $this->transaction->run(function () use ($auction, $adminId, $reason): Auction {
            $auction = $this->auctions->lockForStateChange($auction->id);

            // If seller deposit is zero, skip AwaitingSellerDeposit → go directly to Scheduled
            $targetStatus = $auction->seller_deposit_amount_minor > 0
                ? AuctionStatus::AwaitingSellerDeposit
                : AuctionStatus::Scheduled;

            return $this->stateMachine->transition(
                $auction,
                $targetStatus,
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
            $auction = $this->auctions->lockForStateChange($auction->id);

            return $this->stateMachine->transition($auction, AuctionStatus::Rejected, $adminId, 'admin', $reason);
        });
    }
}
