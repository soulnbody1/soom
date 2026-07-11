<?php

declare(strict_types=1);

namespace App\Repositories\Auction;

use App\Models\Auction\AuctionTermsAcceptance;
use App\Models\Auction\AuctionTermsVersion;

final class AuctionTermsRepository
{
    /**
     * Get the currently active terms version.
     * Used by CreateAuctionAction.
     */
    public function getActiveTermsVersion(): ?AuctionTermsVersion
    {
        return AuctionTermsVersion::where('is_active', true)
            ->latest('version_number')
            ->first();
    }

    /**
     * Check if user has accepted specific terms for an auction.
     * Used by PlaceBidAction.
     */
    public function hasAcceptedTerms(int $auctionId, int $userId, int $termsVersionId): bool
    {
        return AuctionTermsAcceptance::where('auction_id', $auctionId)
            ->where('user_id', $userId)
            ->where('terms_version_id', $termsVersionId)
            ->exists();
    }

    /**
     * Accept terms (idempotent).
     * Used by AcceptAuctionTermsAction.
     */
    public function firstOrCreateAcceptance(array $uniqueAttributes, array $defaults): AuctionTermsAcceptance
    {
        return AuctionTermsAcceptance::firstOrCreate($uniqueAttributes, $defaults);
    }

    /**
     * Deactivate all existing terms versions.
     * Used by CreateAuctionTermsVersionAction.
     */
    public function deactivateAllVersions(): void
    {
        AuctionTermsVersion::query()->update(['is_active' => false]);
    }

    /**
     * Get next version number.
     * Used by CreateAuctionTermsVersionAction.
     */
    public function nextVersionNumber(): int
    {
        return ((int) AuctionTermsVersion::max('version_number')) + 1;
    }

    /**
     * Create a new terms version.
     * Used by CreateAuctionTermsVersionAction.
     */
    public function createVersion(array $attributes): AuctionTermsVersion
    {
        return AuctionTermsVersion::create($attributes);
    }
}
