<?php

declare(strict_types=1);

namespace App\Repositories\Auction;

use App\Models\Auction\AuctionParticipant;

final class AuctionParticipantRepository
{
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
