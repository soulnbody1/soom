<?php

namespace App\Repositories;

use App\Models\AuctionBid;
use App\Models\Auction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class AuctionBidRepository
{
    public function create(array $data): AuctionBid
    {
        return AuctionBid::create($data);
    }

    public function update(AuctionBid $bid, array $data): bool
    {
        return $bid->update($data);
    }

    public function findById(int $id): ?AuctionBid
    {
        return AuctionBid::find($id);
    }

    public function findWinningBid(int $auctionId): ?AuctionBid
    {
        return AuctionBid::where('auction_id', $auctionId)
            ->where('is_winning', true)
            ->first();
    }

    public function getBidsForAuction(int $auctionId, int $perPage = 20): LengthAwarePaginator
    {
        return AuctionBid::where('auction_id', $auctionId)
            ->where('amount', '>', 0)
            ->with('user:id,name,phone,logo')
            ->orderBy('amount', 'desc')
            ->paginate($perPage);
    }

    public function getBidsForUser(int $userId, array $relations = [], int $perPage = 20): LengthAwarePaginator
    {
        return AuctionBid::where('user_id', $userId)
            ->with($relations)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    public function hasUserBid(int $auctionId, int $userId): bool
    {
        return AuctionBid::where('auction_id', $auctionId)
            ->where('user_id', $userId)
            ->exists();
    }

    public function getUserBidForAuction(int $auctionId, int $userId): ?AuctionBid
    {
        return AuctionBid::where('auction_id', $auctionId)
            ->where('user_id', $userId)
            ->first();
    }

    public function getUserBidByTransaction(int $auctionId, int $userId, string $transactionId): ?AuctionBid
    {
        return AuctionBid::where('auction_id', $auctionId)
            ->where('user_id', $userId)
            ->where('deposit_transaction_id', $transactionId)
            ->first();
    }

    public function getHighestBid(int $auctionId): ?AuctionBid
    {
        return AuctionBid::where('auction_id', $auctionId)
            ->where('amount', '>', 0)
            ->orderBy('amount', 'desc')
            ->first();
    }

    public function markBidAsWinning(int $bidId): void
    {
        AuctionBid::where('id', $bidId)->update([
            'is_winning' => true,
            'winning_at' => now(),
        ]);
    }

    public function markPreviousBidsAsOutbid(int $auctionId, int $excludeBidId): void
    {
        AuctionBid::where('auction_id', $auctionId)
            ->where('id', '!=', $excludeBidId)
            ->where('is_winning', true)
            ->update([
                'is_winning' => false,
                'winning_at' => null,
            ]);
    }

    public function markBidDepositPaid(AuctionBid $bid, string $transactionId): void
    {
        $bid->update([
            'deposit_paid' => true,
            'deposit_paid_at' => now(),
            'deposit_transaction_id' => $transactionId,
        ]);
    }

    public function getHeldDepositsForAuction(int $auctionId): Collection
    {
        return AuctionBid::where('auction_id', $auctionId)
            ->where('deposit_status', 'held')
            ->get();
    }

    public function getHeldDepositsForUser(int $userId): Collection
    {
        return AuctionBid::where('user_id', $userId)
            ->where('deposit_status', 'held')
            ->with('auction')
            ->get();
    }

    public function refundAllDepositsForAuction(int $auctionId): void
    {
        AuctionBid::where('auction_id', $auctionId)
            ->where('deposit_status', 'held')
            ->update([
                'deposit_status' => 'refunded',
                'deposit_processed_at' => now(),
            ]);
    }

    public function refundDepositsForLosers(int $auctionId, int $winnerBidId): void
    {
        AuctionBid::where('auction_id', $auctionId)
            ->where('id', '!=', $winnerBidId)
            ->where('deposit_status', 'held')
            ->update([
                'deposit_status' => 'refunded',
                'deposit_processed_at' => now(),
            ]);
    }

    public function applyWinnerDepositToPayment(int $winnerBidId): void
    {
        AuctionBid::where('id', $winnerBidId)
            ->where('deposit_status', 'held')
            ->update([
                'deposit_status' => 'applied_to_payment',
                'deposit_processed_at' => now(),
            ]);
    }

    public function countBidsForAuction(int $auctionId): int
    {
        return AuctionBid::where('auction_id', $auctionId)->count();
    }

    public function countUniqueBidders(int $auctionId): int
    {
        return AuctionBid::where('auction_id', $auctionId)
            ->distinct('user_id')
            ->count('user_id');
    }

    public function lockForUpdate(int $bidId): ?AuctionBid
    {
        return AuctionBid::where('id', $bidId)->lockForUpdate()->first();
    }
}
