<?php

declare(strict_types=1);

namespace App\Domain\Auction\Exceptions;

final class AuctionConfigurationSnapshotImmutableException extends AuctionException
{
    public function __construct()
    {
        parent::__construct(__('auction.errors.configuration_snapshot_immutable'));
        $this->errorCode = 'configuration_snapshot_immutable';
    }
}
