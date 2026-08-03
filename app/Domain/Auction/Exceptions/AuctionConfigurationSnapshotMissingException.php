<?php

declare(strict_types=1);

namespace App\Domain\Auction\Exceptions;

final class AuctionConfigurationSnapshotMissingException extends AuctionException
{
    public function __construct()
    {
        parent::__construct(__('auction.errors.configuration_snapshot_missing'));
        $this->errorCode = 'configuration_snapshot_missing';
    }
}
