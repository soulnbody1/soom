<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\Auction;
use App\Repositories\Auction\AuctionRepository;
use App\Services\Auction\Support\AuctionStateMachine;
use App\Services\Auction\Support\AuctionTransaction;

final class ReopenRejectedAuctionAction
{
    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionStateMachine $stateMachine,
        private readonly AuctionRepository $auctions,
    ) {}

    public function execute(Auction $auction, int $sellerId): Auction
    {
        return $this->transaction->run(function () use ($auction, $sellerId): Auction {
            $auction = $this->auctions->lockForStateChange($auction->id);

            if ($auction->seller_id !== $sellerId) {
                throw AuctionException::domain('seller_only_reopen_auction');
            }

            if ($auction->status !== AuctionStatus::Rejected) {
                throw AuctionException::domain('auction_not_reopenable');
            }

            return $this->stateMachine->transition(
                $auction,
                AuctionStatus::Draft,
                $sellerId,
                'user',
                __('auction.audit.seller_reopened_rejected_auction')
            );
        });
    }
}
