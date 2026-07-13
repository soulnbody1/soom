<?php

declare(strict_types=1);

namespace App\Repositories\Auction;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Models\Auction\AuctionDeposit;
use Illuminate\Support\Collection;

final class AuctionDepositRepository
{
    /**
     * Check if participant has a valid held deposit with sufficient amount.
     * Lock the deposit row for update within a transaction.
     * Used by PlaceBidAction.
     */
    public function lockBidderDeposit(int $participantId): ?AuctionDeposit
    {
        return AuctionDeposit::where('participant_id', $participantId)
            ->where('type', 'bidder')
            ->lockForUpdate()
            ->first();
    }

    /**
     * Find or create a deposit record (idempotent).
     * Used by SubmitPaymentSubmissionAction.
     */
    public function firstOrCreateDeposit(array $uniqueAttributes, array $defaults): AuctionDeposit
    {
        return AuctionDeposit::firstOrCreate($uniqueAttributes, $defaults);
    }

    public function lockPaymentDeposit(int $auctionId, int $userId, string $type): AuctionDeposit
    {
        return AuctionDeposit::where('auction_id', $auctionId)
            ->where('user_id', $userId)
            ->where('type', $type)
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function lockDepositForPayment(int $auctionId, int $userId, string $type): AuctionDeposit
    {
        return $this->lockPaymentDeposit($auctionId, $userId, $type);
    }

    public function lockSellerDepositForAuction(int $auctionId): ?AuctionDeposit
    {
        return AuctionDeposit::where('auction_id', $auctionId)
            ->where('type', 'seller')
            ->lockForUpdate()
            ->first();
    }

    /**
     * Find the winner's deposit for settlement application.
     * Used by FinalizeAuctionAction.
     */
    public function lockWinnerDeposit(int $auctionId, int $userId): ?AuctionDeposit
    {
        return AuctionDeposit::where('auction_id', $auctionId)
            ->where('user_id', $userId)
            ->where('type', 'bidder')
            ->lockForUpdate()
            ->first();
    }

    /**
     * Find defaulted winner's deposit for forfeiture.
     * Used by MarkWinnerDefaultedAction.
     */
    public function lockDepositForForfeiture(int $auctionId, int $userId): ?AuctionDeposit
    {
        return AuctionDeposit::whereHas('auction', function ($query) use ($auctionId): void {
            $query->whereKey($auctionId);
        })
            ->where('user_id', $userId)
            ->where('type', 'bidder')
            ->lockForUpdate()
            ->first();
    }

    public function lockBidderDepositsForAuction(int $auctionId): Collection
    {
        return AuctionDeposit::with(['participant', 'auction'])
            ->where('auction_id', $auctionId)
            ->where('type', 'bidder')
            ->whereIn('status', [
                AuctionDepositStatus::Held->value,
                AuctionDepositStatus::AppliedToSettlement->value,
                AuctionDepositStatus::RefundPending->value,
                AuctionDepositStatus::PendingSubmission->value,
                AuctionDepositStatus::PendingReview->value,
                AuctionDepositStatus::Rejected->value,
            ])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * Lock a deposit by ID for refund processing.
     * Used by RefundAuctionDepositAction.
     */
    public function lockForRefund(int $depositId): AuctionDeposit
    {
        return AuctionDeposit::whereKey($depositId)->lockForUpdate()->firstOrFail();
    }

    /**
     * Save deposit model after in-memory changes.
     */
    public function save(AuctionDeposit $deposit): void
    {
        $deposit->save();
    }
}
