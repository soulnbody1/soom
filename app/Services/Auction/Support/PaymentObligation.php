<?php

declare(strict_types=1);

namespace App\Services\Auction\Support;

use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\AuctionSettlement;

final readonly class PaymentObligation
{
    public function __construct(
        public ?AuctionDeposit $deposit,
        public ?AuctionSettlement $settlement,
        public int $amountMinor,
        public string $currencyCode,
    ) {}

    public function key(): string
    {
        return $this->deposit
            ? FinancialObligationKey::forDeposit($this->deposit)
            : FinancialObligationKey::forSettlement($this->settlement);
    }
}
