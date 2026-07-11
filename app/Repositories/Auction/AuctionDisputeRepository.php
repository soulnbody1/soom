<?php

declare(strict_types=1);

namespace App\Repositories\Auction;

use App\Models\Auction\AuctionDispute;

final class AuctionDisputeRepository
{
    /**
     * Create or find an open dispute (idempotent).
     * Used by OpenAuctionDisputeAction.
     */
    public function firstOrCreateOpenDispute(array $uniqueAttributes, array $defaults): AuctionDispute
    {
        return AuctionDispute::firstOrCreate($uniqueAttributes, $defaults);
    }

    /**
     * Lock a dispute for resolution.
     * Used by ResolveAuctionDisputeAction.
     */
    public function lockForResolution(int $disputeId): AuctionDispute
    {
        return AuctionDispute::whereKey($disputeId)->lockForUpdate()->firstOrFail();
    }

    /**
     * Save dispute model after in-memory changes.
     */
    public function save(AuctionDispute $dispute): void
    {
        $dispute->save();
    }
}
