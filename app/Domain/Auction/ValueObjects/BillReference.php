<?php

declare(strict_types=1);

namespace App\Domain\Auction\ValueObjects;

use InvalidArgumentException;
use Stringable;

/**
 * The unique reference of a single claim a payer can settle.
 *
 * Allocated per payment transaction and stored as the provider transaction id,
 * where the database already guarantees uniqueness per provider. Opaque for the
 * same reasons as BillingReference.
 */
final readonly class BillReference implements Stringable
{
    public const MAX_LENGTH = 64;

    public function __construct(public string $value)
    {
        if ($this->value === '' || strlen($this->value) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('Invalid bill reference.');
        }

        if (preg_match('/\s/', $this->value) === 1) {
            throw new InvalidArgumentException('A bill reference may not contain whitespace.');
        }
    }

    public static function fromString(string $value): self
    {
        return new self(trim($value));
    }

    public static function tryFrom(?string $value): ?self
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            return new self($value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->value, $other->value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
