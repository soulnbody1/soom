<?php

declare(strict_types=1);

namespace App\Domain\Auction\Exceptions;

use RuntimeException;

class AuctionException extends RuntimeException
{
    protected int $statusCode = 422;

    protected ?string $errorCode = null;

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public static function domain(string $key, array $replace = [], int $status = 422): self
    {
        $exception = new self(__('auction.errors.'.$key, $replace));
        $exception->errorCode = $key;
        $exception->statusCode = $status;

        return $exception;
    }

    public static function invalidTransition(string $from, string $to): self
    {
        return self::domain('invalid_transition', ['from' => $from, 'to' => $to]);
    }

    public static function bidRejected(string $key): self
    {
        return self::domain($key);
    }

    public static function paymentRejected(string $key): self
    {
        return self::domain($key);
    }

    public static function configurationRequired(): self
    {
        return self::domain('active_configuration_required');
    }
}
