<?php

declare(strict_types=1);

namespace App\Application\Auction\Actions;

use App\Application\Auction\Services\AuctionStateMachine;
use App\Application\Auction\Services\AuctionTransaction;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\Auction;

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
                throw new AuctionException('Only the seller can submit this auction.');
            }

            if (! $auction->ends_at || ! $auction->starts_at || $auction->ends_at <= $auction->starts_at) {
                throw new AuctionException('Auction start and end times must be valid before review.');
            }

            if (! $auction->terms_version_id) {
                throw new AuctionException('An active auction terms version is required.');
            }

            return $this->stateMachine->transition(
                $auction,
                AuctionStatus::PendingReview,
                $sellerId,
                'user',
                'seller submitted auction for review'
            );
        });
    }
}
