<?php

declare(strict_types=1);

namespace App\DTO\Auction;

use App\Domain\Auction\Enums\SettlementStatus;
use App\DTO\Auction\Contracts\PersistenceDTO;
use Carbon\CarbonInterface;

final readonly class CreateSettlementDTO extends BaseAuctionDTO implements PersistenceDTO
{
    public function __construct(
        public int $auction_id,
        public int $winning_bid_id,
        public int $winner_id,
        public SettlementStatus $status,
        public int $winning_amount_minor,
        public int $deposit_applied_minor,
        public int $platform_fee_minor,
        public int $seller_net_amount_minor,
        public int $amount_due_minor,
        public int $amount_paid_minor,
        public int $remaining_amount_minor,
        public string $currency_code,
        public ?CarbonInterface $payment_due_at = null,
        public ?CarbonInterface $handover_due_at = null,
    ) {}

    public function toPersistenceArray(): array
    {
        return [
            'auction_id' => $this->auction_id,
            'winning_bid_id' => $this->winning_bid_id,
            'winner_id' => $this->winner_id,
            'status' => $this->status,
            'winning_amount_minor' => $this->winning_amount_minor,
            'deposit_applied_minor' => $this->deposit_applied_minor,
            'platform_fee_minor' => $this->platform_fee_minor,
            'seller_net_amount_minor' => $this->seller_net_amount_minor,
            'amount_due_minor' => $this->amount_due_minor,
            'amount_paid_minor' => $this->amount_paid_minor,
            'remaining_amount_minor' => $this->remaining_amount_minor,
            'currency_code' => $this->currency_code,
            'payment_due_at' => $this->payment_due_at,
            'handover_due_at' => $this->handover_due_at,
        ];
    }

    public function toArray(): array
    {
        return $this->toPersistenceArray();
    }
}
