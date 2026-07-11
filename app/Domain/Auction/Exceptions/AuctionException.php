<?php

declare(strict_types=1);

namespace App\Domain\Auction\Exceptions;

use RuntimeException;

final class AuctionException extends RuntimeException
{
    public static function invalidTransition(string $from, string $to): self
    {
        return new self("Auction cannot transition from {$from} to {$to}.");
    }

    public static function bidRejected(string $reason): self
    {
        return new self($reason);
    }

    public static function paymentRejected(string $reason): self
    {
        return new self($reason);
    }
}
