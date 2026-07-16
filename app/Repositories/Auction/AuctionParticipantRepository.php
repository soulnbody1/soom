<?php

declare(strict_types=1);

namespace App\Repositories\Auction;

use App\Models\Auction\Auction;
use App\Models\Auction\AuctionParticipant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class AuctionParticipantRepository
{
    /**
     * Paginate participants of an auction for the admin dashboard.
     * Used by AuctionController::participants.
     */
    public function paginateByAuction(Auction $auction, ?string $status, int $perPage): LengthAwarePaginator
    {
        return AuctionParticipant::with('user')
            ->where('auction_id', $auction->id)
            ->when($status, fn ($query, string $value) => $query->where('status', $value))
            ->orderBy('registered_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Find a participant with lockForUpdate for bid validation.
     * Used by PlaceBidAction within transaction.
     */
    public function lockParticipant(int $auctionId, int $userId): ?AuctionParticipant
    {
        return AuctionParticipant::where('auction_id', $auctionId)
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Find or create a participant (idempotent registration).
     * Used by RegisterParticipantAction.
     */
    public function firstOrCreateParticipant(int $auctionId, int $userId, array $defaults): AuctionParticipant
    {
        return AuctionParticipant::firstOrCreate(
            ['auction_id' => $auctionId, 'user_id' => $userId],
            $defaults
        );
    }

    /**
     * Find existing participant by auction and user (without lock).
     * Used by AcceptAuctionTermsAction.
     */
    public function findByAuctionAndUser(int $auctionId, int $userId): ?AuctionParticipant
    {
        return AuctionParticipant::where('auction_id', $auctionId)
            ->where('user_id', $userId)
            ->first();
    }

    public function save(AuctionParticipant $participant): void
    {
        $participant->save();
    }
}
