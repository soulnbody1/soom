<?php

declare(strict_types=1);

namespace App\Domain\Auction\Exceptions;

final class AuctionConfigurationVersionInUseException extends AuctionException
{
    public function __construct()
    {
        parent::__construct(__('auction.errors.configuration_version_in_use'));
    }
}
