<?php

declare(strict_types=1);

namespace App\Domain\Auction\ValueObjects;

use InvalidArgumentException;
use Stringable;

/**
 * The stable reference a payer quotes to look up what they owe.
 *
 * Allocated once per payer per provider and never reissued. Deliberately opaque:
 * nothing in the platform interprets it, derives anything from it, or encodes
 * internal identifiers into it.
 */
final readonly class BillingReference implements Stringable
{
    public const MAX_LENGTH = 40;

    public function __construct(public string $value)
    {
        if ($this->value === '' || strlen($this->value) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('Invalid billing reference.');
        }

        if (preg_match('/\s/', $this->value) === 1) {
            throw new InvalidArgumentException('A billing reference may not contain whitespace.');
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
