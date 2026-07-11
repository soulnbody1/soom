<?php

declare(strict_types=1);

namespace App\Domain\Auction\ValueObjects;

use InvalidArgumentException;

final readonly class Money
{
    private const MAX_MINOR_UNITS = PHP_INT_MAX;

    public function __construct(
        public int $minor,
        public string $currency
    ) {
        if ($minor < 0) {
            throw new InvalidArgumentException('Money amount cannot be negative.');
        }

        Currency::fromCode($currency);
    }

    public static function fromDecimalString(string|int $amount, string $currency): self
    {
        $amount = trim((string) $amount);
        $currency = Currency::fromCode($currency);
        $exponent = $currency->exponent();

        if (! preg_match('/^\d+(\.\d+)?$/', $amount)) {
            throw new InvalidArgumentException('Money amount must be a positive decimal string.');
        }

        [$units, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');

        if (strlen($fraction) > $exponent) {
            throw new InvalidArgumentException('Money amount has too many decimal places for the currency.');
        }

        $fraction = str_pad($fraction, $exponent, '0');
        $minor = self::checkedAdd(
            self::checkedMultiply((int) $units, $currency->scale()),
            (int) $fraction
        );

        return new self($minor, $currency->code);
    }

    public static function fromMinorUnits(int $minor, string $currency): self
    {
        return new self($minor, Currency::fromCode($currency)->code);
    }

    public static function zero(string $currency): self
    {
        return new self(0, Currency::fromCode($currency)->code);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(self::checkedAdd($this->minor, $other->minor), $this->currency);
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

    public function compare(self $other): int
    {
        $this->assertSameCurrency($other);

        return $this->minor <=> $other->minor;
    }

    public function isSameCurrency(self $other): bool
    {
        return $this->currency === $other->currency;
    }

    public function toDecimalString(): string
    {
        $currency = Currency::fromCode($this->currency);
        $exponent = $currency->exponent();
        $scale = $currency->scale();
        $units = intdiv($this->minor, $scale);
        $fraction = (string) ($this->minor % $scale);

        if ($exponent === 0) {
            return (string) $units;
        }

        return $units.'.'.str_pad($fraction, $exponent, '0', STR_PAD_LEFT);
    }

    public function toApi(): array
    {
        return [
            'amount' => $this->toDecimalString(),
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

    private static function checkedAdd(int $left, int $right): int
    {
        if ($right > self::MAX_MINOR_UNITS - $left) {
            throw new InvalidArgumentException('Money amount overflow.');
        }

        return $left + $right;
    }

    private static function checkedMultiply(int $left, int $right): int
    {
        if ($left !== 0 && $right > intdiv(self::MAX_MINOR_UNITS, $left)) {
            throw new InvalidArgumentException('Money amount overflow.');
        }

        return $left * $right;
    }
}
