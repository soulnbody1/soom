<?php

declare(strict_types=1);

namespace App\Domain\Auction\ValueObjects;

use InvalidArgumentException;

final readonly class Money
{
    public function __construct(
        public int $minor,
        public string $currency
    ) {
        if ($minor < 0) {
            throw new InvalidArgumentException('Money amount cannot be negative.');
        }

        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('Currency must be an ISO-4217 code.');
        }
    }

    public static function fromDecimalString(string|int $amount, string $currency): self
    {
        $amount = trim((string) $amount);

        if (! preg_match('/^\d+(\.\d{1,2})?$/', $amount)) {
            throw new InvalidArgumentException('Money amount must be a decimal string with up to two decimals.');
        }

        [$units, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');
        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');

        return new self(((int) $units * 100) + (int) $fraction, strtoupper($currency));
    }

    public static function zero(string $currency): self
    {
        return new self(0, strtoupper($currency));
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor + $other->minor, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        if ($other->minor > $this->minor) {
            throw new InvalidArgumentException('Money subtraction cannot produce a negative amount.');
        }

        return new self($this->minor - $other->minor, $this->currency);
    }

    public function greaterThanOrEqual(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minor >= $other->minor;
    }

    public function format(): string
    {
        return number_format($this->minor / 100, 2, '.', '');
    }

    public function toApi(): array
    {
        return [
            'amount' => $this->format(),
            'minor' => $this->minor,
            'currency' => $this->currency,
        ];
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException('Cannot operate on different currencies.');
        }
    }
}
