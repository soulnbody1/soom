<?php

declare(strict_types=1);

namespace App\DTO\Auction;

use App\Domain\Auction\Enums\SellerDepositDisposition;

final readonly class SellerDepositDispositionDTO
{
    public function __construct(
        public SellerDepositDisposition $disposition,
        public string $policyKey,
        public string $trigger,
        public string $reason,
        public int $forfeitAmountMinor = 0,
    ) {}

    public function metadata(): array
    {
        return [
            'disposition' => $this->disposition->value,
            'policy_key' => $this->policyKey,
            'trigger' => $this->trigger,
            'reason' => $this->reason,
            'forfeit_amount_minor' => $this->forfeitAmountMinor,
        ];
    }
}
