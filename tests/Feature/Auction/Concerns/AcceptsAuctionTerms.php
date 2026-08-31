<?php

declare(strict_types=1);

namespace Tests\Feature\Auction\Concerns;

use App\Models\Auction\Auction;
use App\Models\Auction\AuctionConfigurationSnapshot;
use App\Models\Auction\AuctionTermsVersion;

trait AcceptsAuctionTerms
{
    protected function requiredTermsVersionId(Auction $auction): string
    {
        $versionId = AuctionConfigurationSnapshot::where('auction_id', $auction->id)->value('terms_version_id')
            ?? $auction->terms_version_id;

        return (string) AuctionTermsVersion::whereKey($versionId)->value('public_id');
    }

    protected function termsBody(Auction $auction): array
    {
        return ['terms_version_id' => $this->requiredTermsVersionId($auction)];
    }
}
