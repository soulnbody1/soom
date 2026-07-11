<?php

declare(strict_types=1);

namespace App\Repositories\Auction;

use App\Models\Auction\AuctionSettlement;

final class AuctionSettlementRepository
{
    /**
     * Lock the current settlement for an auction.
     * Used by MarkWinnerDefaultedAction, ConfirmHandoverAction, ConfirmReceiptAction, etc.
     */
    public function lockSettlement(int $auctionId): AuctionSettlement
    {
        return AuctionSettlement::where('auction_id', $auctionId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Find settlement by auction and winner (without lock).
     * Used by SubmitPaymentSubmissionAction.
     */
    public function findByAuctionAndWinner(int $auctionId, int $winnerId): ?AuctionSettlement
    {
        return AuctionSettlement::where('auction_id', $auctionId)
            ->where('winner_id', $winnerId)
            ->first();
    }

    /**
     * Create a new settlement record.
     * Used by FinalizeAuctionAction.
     */
    public function createSettlement(array $attributes): AuctionSettlement
    {
        return AuctionSettlement::create($attributes);
    }

    /**
     * Save settlement model after in-memory changes.
     */
    public function save(AuctionSettlement $settlement): void
    {
        $settlement->save();
    }
}
