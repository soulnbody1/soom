<?php

namespace App\Repositories;

use App\Models\AuctionBid;
use App\Models\Auction;
use App\Models\AuctionDeposit;
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
            ->whereNotNull('is_winning')
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
        // البحث عن المزايدة من خلال جدول التأمين الموحد
        $deposit = AuctionDeposit::where('depositable_type', AuctionBid::class)
            ->whereHasMorph('depositable', [AuctionBid::class], function ($q) use ($auctionId, $userId) {
                $q->where('auction_id', $auctionId)
                  ->where('user_id', $userId);
            })
            ->where('transaction_id', $transactionId)
            ->first();

        return $deposit?->depositable;
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
        $bid = $this->findById($bidId);
        if ($bid) {
            $bid->markAsWinning();
        }
    }

    public function markPreviousBidsAsOutbid(int $auctionId, int $excludeBidId): void
    {
        AuctionBid::where('auction_id', $auctionId)
            ->where('id', '!=', $excludeBidId)
            ->whereNotNull('is_winning')
            ->update([
                'is_winning' => null,
                'winning_at' => null,
            ]);
    }

    public function markBidDepositPaid(AuctionBid $bid, string $transactionId, ?float $amount = null): void
    {
        AuctionDeposit::create([
            'user_id' => $bid->user_id,
            'depositable_id' => $bid->id,
            'depositable_type' => AuctionBid::class,
            'paid' => false,
            'paid_at' => now(),
            'transaction_id' => $transactionId,
            'amount' => $amount,
            'deposit_status' => 'held',
            'deposit_type' => 'bidder',
        ]);
    }

    public function getHeldDepositsForAuction(int $auctionId): Collection
    {
        return AuctionDeposit::where('depositable_type', AuctionBid::class)
            ->whereHasMorph('depositable', [AuctionBid::class], function ($q) use ($auctionId) {
                $q->where('auction_id', $auctionId);
            })
            ->where('deposit_status', 'held')
            ->get();
    }

    public function getHeldDepositsForUser(int $userId): Collection
    {
        return AuctionDeposit::with('depositable.auction')
            ->where('user_id', $userId)
            ->where('deposit_status', 'held')
            ->where('deposit_type', 'bidder')
            ->get();
    }

    public function refundAllDepositsForAuction(int $auctionId): void
    {
        AuctionDeposit::where('depositable_type', AuctionBid::class)
            ->whereHasMorph('depositable', [AuctionBid::class], function ($q) use ($auctionId) {
                $q->where('auction_id', $auctionId);
            })
            ->where('deposit_status', 'held')
            ->update([
                'deposit_status' => 'refunded',
                'processed_at' => now(),
            ]);
    }

    public function refundDepositsForLosers(int $auctionId, int $winnerBidId): void
    {
        AuctionDeposit::where('depositable_type', AuctionBid::class)
            ->whereHasMorph('depositable', [AuctionBid::class], function ($q) use ($auctionId, $winnerBidId) {
                $q->where('auction_id', $auctionId)
                  ->where('id', '!=', $winnerBidId);
            })
            ->where('deposit_status', 'held')
            ->update([
                'deposit_status' => 'refunded',
                'processed_at' => now(),
            ]);
    }

    public function applyWinnerDepositToPayment(int $winnerBidId): void
    {
        AuctionDeposit::where('depositable_type', AuctionBid::class)
            ->where('depositable_id', $winnerBidId)
            ->where('deposit_status', 'held')
            ->update([
                'deposit_status' => 'applied_to_payment',
                'processed_at' => now(),
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
