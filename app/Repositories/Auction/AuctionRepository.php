<?php

declare(strict_types=1);

namespace App\Repositories\Auction;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Models\Auction\Auction;
use Illuminate\Support\Collection;

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

    /**
     * Find auction by public_id without locking.
     */
    public function findByPublicId(string $publicId): ?Auction
    {
        return Auction::where('public_id', $publicId)->first();
    }

    /**
     * Find auction by public_id ensuring seller ownership.
     */
    public function findByPublicIdForSeller(string $publicId, int $sellerId): ?Auction
    {
        return Auction::where('public_id', $publicId)
            ->where('seller_id', $sellerId)
            ->first();
    }

    /**
     * Update auction's leading bid reference.
     * Preserves time-extension logic from PlaceBidAction.
     */
    public function setLeadingBid(Auction $auction, int $bidId): void
    {
        $auction->forceFill(['current_leading_bid_id' => $bidId])->save();
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
    public function findDueForStart(): Collection
    {
        return Auction::where('status', AuctionStatus::Scheduled->value)
            ->whereNotNull('starts_at')
            ->where('starts_at', '<=', now())
            ->get();
    }

    /**
     * Find live auctions past their scheduled end time.
     * Used by FinalizeExpiredAuctionsJob.
     */
    public function findExpiredLiveAuctions(): Collection
    {
        return Auction::where('status', AuctionStatus::Live->value)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', now())
            ->get();
    }

    /**
     * Find auctions needing deposit refunds after finalization.
     * Used by RefundPendingAuctionDepositsJob.
     */
    public function findAuctionsNeedingDepositRefund(): Collection
    {
        return Auction::whereIn('status', [
            AuctionStatus::Unsold->value,
            AuctionStatus::Cancelled->value,
            AuctionStatus::Completed->value,
            AuctionStatus::Defaulted->value,
        ])
            ->whereHas('deposits', function ($query): void {
                $query->where('status', 'refund_pending');
            })
            ->get();
    }
}
