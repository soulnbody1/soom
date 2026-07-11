<?php

declare(strict_types=1);

namespace App\Domain\Auction\Exceptions;

use RuntimeException;

class AuctionException extends RuntimeException
{
    protected int $statusCode = 422;

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public static function invalidTransition(string $from, string $to): self
    {
        return new self(__('auction.errors.invalid_transition', ['from' => $from, 'to' => $to]));
    }

    public static function bidRejected(string $reason): self
    {
        return new self($reason);
    }

    public static function paymentRejected(string $reason): self
    {
        return new self($reason);
    }

    public static function configurationRequired(): self
    {
        return new self(__('auction.errors.active_configuration_required'));
    }
}

