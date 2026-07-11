<?php

declare(strict_types=1);

namespace App\DTO\Auction;

use JsonSerializable;

abstract readonly class BaseAuctionDTO implements JsonSerializable
{
    abstract public function toArray(): array;

    final public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
