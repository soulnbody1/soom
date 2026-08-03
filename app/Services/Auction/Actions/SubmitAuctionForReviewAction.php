<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\Auction;
use App\Repositories\Auction\AuctionRepository;
use App\Services\Auction\Support\AuctionStateMachine;
use App\Services\Auction\Support\AuctionTransaction;

final class SubmitAuctionForReviewAction
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
                throw AuctionException::domain('seller_only_submit_review');
            }

            if (! $auction->ends_at || ! $auction->starts_at || $auction->ends_at <= $auction->starts_at) {
                throw AuctionException::domain('invalid_auction_times');
            }

            if (! $auction->terms_version_id) {
                throw AuctionException::domain('active_terms_required');
            }

            return $this->stateMachine->transition(
                $auction,
                AuctionStatus::PendingReview,
                $sellerId,
                'user',
                __('auction.audit.seller_submitted_review')
            );
        });
    }
}
