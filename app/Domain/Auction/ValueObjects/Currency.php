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
    ];

    public function __construct(public string $code)
    {
        if (! array_key_exists($code, self::EXPONENTS)) {
            throw new InvalidArgumentException('Unsupported auction currency.');
        }
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
