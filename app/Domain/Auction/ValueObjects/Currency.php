<?php

declare(strict_types=1);

namespace App\Domain\Auction\ValueObjects;

use InvalidArgumentException;

final readonly class Currency
{
    private const EXPONENTS = [
        'JOD' => 3,
        'EGP' => 2,
        'USD' => 2,
        'AED' => 2,
    ];

    public function __construct(public string $code)
    {
        if (! array_key_exists($code, self::EXPONENTS)) {
            throw new InvalidArgumentException('Unsupported auction currency.');
        }
    }

    public static function supportedCodes(): array
    {
        return array_keys(self::EXPONENTS);
    }

    public static function isSupported(string $code): bool
    {
        return array_key_exists(strtoupper($code), self::EXPONENTS);
    }

    public static function fromCode(string $code): self
    {
        return new self(strtoupper($code));
    }

    public function exponent(): int
    {
        return self::EXPONENTS[$this->code];
    }

    public function scale(): int
    {
        return 10 ** $this->exponent();
    }
}
