<?php

declare(strict_types=1);

namespace App\Domain\Auction\Exceptions;

final class AuctionConfigurationSnapshotIncompleteException extends AuctionException
{
    public function __construct(public readonly array $errors = [])
    {
        parent::__construct(__('auction.errors.configuration_snapshot_incomplete'));
    }
}
