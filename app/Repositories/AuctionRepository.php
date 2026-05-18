<?php

namespace App\Repositories;

use App\Models\Auction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class AuctionRepository
{
    public function create(array $data): Auction
    {
        return Auction::create($data);
    }

    public function update(Auction $auction, array $data): bool
    {
        return $auction->update($data);
    }

    public function findById(int $id, array $relations = []): ?Auction
    {
        $query = Auction::query();
        
        if (!empty($relations)) {
            $query->with($relations);
        }
        
        return $query->find($id);
    }

    public function getActiveAuctions(array $relations = [], int $perPage = 20): LengthAwarePaginator
    {
        return Auction::with($relations)
            ->active()
            ->orderBy('ends_at', 'asc')
            ->paginate($perPage);
    }

    public function getExpiredAuctions(): Collection
    {
        return Auction::expired()
            ->with(['bids'])
            ->get();
    }

    public function getAuctionsForUser(int $userId, array $relations = [], int $perPage = 20): LengthAwarePaginator
    {
        return Auction::with($relations)
            ->forUser($userId)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    public function incrementBidsCount(Auction $auction): void
    {
        $auction->increment('bids_count');
    }

    public function incrementViewsCount(Auction $auction): void
    {
        $auction->increment('views_count');
    }

    public function updateUniqueBiddersCount(Auction $auction): void
    {
        $count = $auction->bids()->distinct('user_id')->count('user_id');
        $auction->update(['unique_bidders_count' => $count]);
    }

    public function markAdvertiserDepositPaid(Auction $auction, string $transactionId): void
    {
        $auction->update([
            'advertiser_deposit_paid' => true,
            'advertiser_deposit_paid_at' => now(),
            'advertiser_deposit_transaction_id' => $transactionId,
            'status' => 'active',
            'starts_at' => now(),
        ]);
    }

    public function setWinner(Auction $auction, int $winnerId): void
    {
        $auction->update([
            'winner_id' => $winnerId,
            'status' => 'completed',
        ]);
    }

    public function close(Auction $auction, string $reason = null): void
    {
        $auction->update([
            'status' => 'closed',
        ]);
    }

    public function cancel(Auction $auction, string $reason = null): void
    {
        $auction->update([
            'status' => 'cancelled',
        ]);
    }

    public function extendIfNeeded(Auction $auction, int $minutes = 10): bool
    {
        if ($auction->shouldExtend()) {
            $auction->extend($minutes);
            return true;
        }
        
        return false;
    }

    public function updateCurrentBid(Auction $auction, float $amount): void
    {
        $auction->update([
            'current_bid' => $amount,
        ]);
    }

    public function lockForUpdate(int $auctionId): ?Auction
    {
        return Auction::where('id', $auctionId)->lockForUpdate()->first();
    }
}
