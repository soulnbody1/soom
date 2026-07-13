<?php

declare(strict_types=1);

namespace App\Repositories\Auction;

use App\Domain\Auction\Enums\AuctionStatus;
use App\DTO\Auction\CreateAuctionRecordDTO;
use App\Models\Auction\Auction;
use Illuminate\Support\LazyCollection;

final class AuctionRepository
{
    /**
     * Lock an auction row for bidding operations.
     * Used within transactions by PlaceBidAction.
     */
    public function lockForBidding(int $auctionId): Auction
    {
        return Auction::whereKey($auctionId)->lockForUpdate()->firstOrFail();
    }

    /**
     * Lock an auction row for finalization.
     * Used within transactions by FinalizeAuctionAction.
     */
    public function lockForFinalization(int $auctionId): Auction
    {
        return Auction::whereKey($auctionId)->lockForUpdate()->firstOrFail();
    }

    /**
     * Lock an auction row for generic state-changing operations.
     * Used by RegisterParticipantAction, SubmitPaymentSubmissionAction, etc.
     */
    public function lockForStateChange(int $auctionId): Auction
    {
        return Auction::whereKey($auctionId)->lockForUpdate()->firstOrFail();
    }

    public function lockAuctionForPayment(int $auctionId): Auction
    {
        return $this->lockForStateChange($auctionId);
    }

    /**
     * Save auction with finalization data (winning_bid_id).
     */
    public function setWinningBid(Auction $auction, int $bidId): void
    {
        $auction->forceFill(['winning_bid_id' => $bidId])->save();
    }

    /**
     * Save auction model after in-memory changes (e.g. time extension).
     */
    public function save(Auction $auction): void
    {
        $auction->save();
    }

    /**
     * Find approved auctions that are due to start (past scheduled start time, status = Scheduled).
     * Used by StartDueAuctionsAction.
     */
    public function findDueForStart(): LazyCollection
    {
        return Auction::where('status', AuctionStatus::Scheduled->value)
            ->whereNotNull('starts_at')
            ->where('starts_at', '<=', now())
            ->lazyById(100);
    }

    /**
     * Find live auctions past their scheduled end time.
     * Used by FinalizeExpiredAuctionsJob.
     */
    public function findExpiredLiveAuctions(): LazyCollection
    {
        return Auction::where('status', AuctionStatus::Live->value)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', now())
            ->lazyById(100);
    }

    /**
     * Create a new auction from a trusted persistence DTO.
     * Platform-controlled fields are already set via configuration snapshot.
     */
    public function createFromDTO(CreateAuctionRecordDTO $dto): Auction
    {
        return Auction::create($dto->toPersistenceArray());
    }
}
