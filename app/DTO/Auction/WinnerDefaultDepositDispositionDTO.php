<?php

declare(strict_types=1);

namespace App\DTO\Auction;

use App\Domain\Auction\Enums\WinnerDefaultDepositDisposition;

final readonly class WinnerDefaultDepositDispositionDTO
{
    public function __construct(
        public WinnerDefaultDepositDisposition $disposition,
        public string $policyKey,
        public string $reason,
        public int $forfeitAmountMinor = 0,
    ) {}

    public function metadata(): array
    {
        return [
            'disposition' => $this->disposition->value,
            'policy_key' => $this->policyKey,
            'reason' => $this->reason,
            'forfeit_amount_minor' => $this->forfeitAmountMinor,
        ];
    }
}
