<?php

declare(strict_types=1);

namespace App\Services\Auction\Payments\Fees;

use App\Domain\Auction\Enums\CustomerFeeBasis;
use InvalidArgumentException;

final readonly class CustomerFeeSchedule
{
    /**
     * @param  list<CustomerFeeTier>  $tiers
     */
    private function __construct(
        public CustomerFeeBasis $basis,
        public array $tiers,
    ) {}

    public static function fromConfiguration(?string $basis, mixed $tiers): self
    {
        $resolved = CustomerFeeBasis::tryFrom((string) $basis);

        if ($resolved === null) {
            throw new InvalidArgumentException('A fee schedule needs a supported fee basis.');
        }

        if (! is_array($tiers) || $tiers === []) {
            throw new InvalidArgumentException('A fee schedule needs at least one tier.');
        }

        $parsed = [];

        foreach (array_values($tiers) as $tier) {
            if (! is_array($tier)) {
                throw new InvalidArgumentException('Every fee tier must be an object.');
            }

            $parsed[] = CustomerFeeTier::fromArray($tier);
        }

        usort($parsed, static fn (CustomerFeeTier $a, CustomerFeeTier $b): int => $a->fromMinor <=> $b->fromMinor);

        foreach ($parsed as $index => $tier) {
            foreach (array_slice($parsed, $index + 1) as $later) {
                if ($tier->overlaps($later)) {
                    throw new InvalidArgumentException('Fee tiers must not overlap.');
                }
            }
        }

        return new self($resolved, $parsed);
    }

    public static function isConfigured(?string $basis, mixed $tiers): bool
    {
        return CustomerFeeBasis::tryFrom((string) $basis) !== null && is_array($tiers) && $tiers !== [];
    }

    public function feeFor(int $principalMinor): int
    {
        return match ($this->basis) {
            CustomerFeeBasis::Principal => $this->tierCovering($principalMinor)->feeMinor,
            CustomerFeeBasis::FinalPayable => $this->feeFromPayable($principalMinor),
        };
    }

    private function feeFromPayable(int $principalMinor): int
    {
        foreach ($this->tiers as $tier) {
            if ($tier->covers($principalMinor + $tier->feeMinor)) {
                return $tier->feeMinor;
            }
        }

        throw new CustomerFeeTierMissing('No fee tier covers this amount.');
    }

    private function tierCovering(int $amountMinor): CustomerFeeTier
    {
        foreach ($this->tiers as $tier) {
            if ($tier->covers($amountMinor)) {
                return $tier;
            }
        }

        throw new CustomerFeeTierMissing('No fee tier covers this amount.');
    }

    public function toArray(): array
    {
        return array_map(static fn (CustomerFeeTier $tier): array => $tier->toArray(), $this->tiers);
    }
}
