<?php

declare(strict_types=1);

namespace App\Repositories\Auction;

use App\Domain\Auction\Exceptions\AuctionConfigurationSnapshotIncompleteException;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionConfigurationSnapshot;
use App\Models\Auction\AuctionConfigurationVersion;
use App\Services\Auction\Support\AuctionConfigurationSnapshotFactory;

final class AuctionConfigurationSnapshotRepository
{
    public function __construct(
        private readonly AuctionConfigurationSnapshotFactory $factory,
    ) {}

    public function findForAuction(int $auctionId): ?AuctionConfigurationSnapshot
    {
        return AuctionConfigurationSnapshot::where('auction_id', $auctionId)->first();
    }

    public function createForApprovedAuction(Auction $auction, ?int $createdBy): AuctionConfigurationSnapshot
    {
        $existing = $this->findForAuction((int) $auction->id);
        if ($existing) {
            return $existing;
        }

        if (! $auction->configuration_version_id) {
            throw new AuctionConfigurationSnapshotIncompleteException(['configuration_version_id: required']);
        }

        $sourceVersion = AuctionConfigurationVersion::whereKey($auction->configuration_version_id)
            ->lockForUpdate()
            ->first();

        if (! $sourceVersion) {
            throw new AuctionConfigurationSnapshotIncompleteException(['source_configuration_version_id: not found']);
        }

        $auction->loadMissing('termsVersion');
        $attributes = $this->factory->fromAuction($auction, $sourceVersion, $createdBy);

        return AuctionConfigurationSnapshot::create($attributes);
    }
}
