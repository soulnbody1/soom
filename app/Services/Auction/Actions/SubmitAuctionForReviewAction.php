<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\Auction;
use App\Services\Auction\Support\AuctionStateMachine;
use App\Services\Auction\Support\AuctionTransaction;

final class SubmitAuctionForReviewAction
{
    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionStateMachine $stateMachine
    ) {}

    public function execute(Auction $auction, int $sellerId): Auction
    {
        return $this->transaction->run(function () use ($auction, $sellerId): Auction {
            $auction = Auction::whereKey($auction->id)->lockForUpdate()->firstOrFail();

            if ($auction->seller_id !== $sellerId) {
                throw new AuctionException(__('auction.errors.seller_only_submit_review'));
            }

            if (! $auction->ends_at || ! $auction->starts_at || $auction->ends_at <= $auction->starts_at) {
                throw new AuctionException(__('auction.errors.invalid_auction_times'));
            }

            if (! $auction->terms_version_id) {
                throw new AuctionException(__('auction.errors.active_terms_required'));
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
