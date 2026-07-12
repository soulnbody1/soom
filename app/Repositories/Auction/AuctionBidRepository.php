<?php

declare(strict_types=1);

namespace App\Repositories\Auction;

use App\DTO\Auction\CreateBidRecordDTO;
use App\Models\Auction\AuctionBid;
use Illuminate\Support\Collection;

final class AuctionBidRepository
{
    /**
     * Find an existing bid by idempotency key (duplicate detection).
     * Used by PlaceBidAction for idempotent bid creation.
     */
    public function findByIdempotencyKey(int $auctionId, int $bidderId, string $idempotencyKey): ?AuctionBid
    {
        return AuctionBid::where('auction_id', $auctionId)
            ->where('bidder_id', $bidderId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    /**
     * Get next sequence number for a bid in this auction.
     */
    public function nextSequenceNumber(int $auctionId): int
    {
        return ((int) AuctionBid::where('auction_id', $auctionId)->max('sequence_number')) + 1;
    }

    /**
     * Create a new accepted bid record.
     */
    public function createAcceptedBid(CreateBidRecordDTO $dto): AuctionBid
    {
        return AuctionBid::create($dto->toPersistenceArray());
    }

    /**
     * Get the winning bid (highest amount, earliest sequence) with lockForUpdate.
     * Used by FinalizeAuctionAction.
     */
    public function lockWinningBid(int $auctionId): ?AuctionBid
    {
        return AuctionBid::where('auction_id', $auctionId)
            ->orderByDesc('amount_minor')
            ->orderBy('sequence_number')
            ->lockForUpdate()
            ->first();
    }

    /**
     * Get the next highest bid excluding a specific bid (for winner reassignment).
     * Used by MarkWinnerDefaultedAction.
     */
    public function lockNextHighestBidExcluding(int $auctionId, int $excludeBidId): ?AuctionBid
    {
        return AuctionBid::where('auction_id', $auctionId)
            ->where('id', '!=', $excludeBidId)
            ->orderByDesc('amount_minor')
            ->orderBy('sequence_number')
            ->lockForUpdate()
            ->first();
    }

    /**
     * Lock alternative winner candidates ordered by auction precedence.
     */
    public function lockAlternativeWinnerCandidates(int $auctionId, int $defaultedUserId): Collection
    {
        return AuctionBid::where('auction_id', $auctionId)
            ->where('bidder_id', '!=', $defaultedUserId)
            ->orderByDesc('amount_minor')
            ->orderBy('sequence_number')
            ->lockForUpdate()
            ->get();
    }

    public function lockRankedBids(int $auctionId): Collection
    {
        return AuctionBid::with('participant')
            ->where('auction_id', $auctionId)
            ->orderByDesc('amount_minor')
            ->orderBy('sequence_number')
            ->lockForUpdate()
            ->get();
    }
}
