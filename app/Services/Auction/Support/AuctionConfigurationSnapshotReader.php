<?php

declare(strict_types=1);

namespace App\Services\Auction\Support;

use App\Domain\Auction\Exceptions\AuctionConfigurationSnapshotIncompleteException;
use App\Domain\Auction\Exceptions\AuctionConfigurationSnapshotMissingException;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionConfigurationSnapshot;

final class AuctionConfigurationSnapshotReader
{
    public function __construct(
        private readonly AuctionConfigurationSnapshotHasher $hasher,
        private readonly AuctionConfigurationSnapshotValidator $validator,
    ) {}

    public function forAuction(Auction $auction): AuctionConfigurationSnapshot
    {
        $snapshot = $auction->relationLoaded('configurationSnapshot')
            ? $auction->configurationSnapshot
            : AuctionConfigurationSnapshot::where('auction_id', $auction->id)->first();

        if (! $snapshot) {
            throw new AuctionConfigurationSnapshotMissingException;
        }

        $data = $snapshot->toArray();

        $this->validator->assertValid($data);

        if ($this->hasher->hash($data) !== $snapshot->snapshot_hash) {
            throw new AuctionConfigurationSnapshotIncompleteException(['snapshot_hash: mismatch']);
        }

        return $snapshot;
    }
}
