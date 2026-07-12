<?php

declare(strict_types=1);

namespace App\DTO\Auction;

use App\Domain\Auction\Enums\SettlementStatus;
use App\DTO\Auction\Contracts\PersistenceDTO;
use Carbon\CarbonInterface;

final readonly class CreateSettlementDTO extends BaseAuctionDTO implements PersistenceDTO
{
    public function __construct(
        public int $auctionId,
        public int $winningBidId,
        public int $winnerId,
        public SettlementStatus $status,
        public int $winningAmountMinor,
        public int $depositAppliedMinor,
        public int $platformFeeMinor,
        public int $sellerNetAmountMinor,
        public int $amountDueMinor,
        public int $amountPaidMinor,
        public int $remainingAmountMinor,
        public string $currencyCode,
        public ?CarbonInterface $paymentDueAt = null,
        public ?CarbonInterface $handoverDueAt = null,
        public ?CarbonInterface $paidAt = null,
        public ?int $previousSettlementId = null,
        public ?int $winnerReassignmentId = null,
    ) {}

    public function toPersistenceArray(): array
    {
        return [
            'auction_id' => $this->auctionId,
            'winning_bid_id' => $this->winningBidId,
            'winner_id' => $this->winnerId,
            'status' => $this->status,
            'winning_amount_minor' => $this->winningAmountMinor,
            'deposit_applied_minor' => $this->depositAppliedMinor,
            'platform_fee_minor' => $this->platformFeeMinor,
            'seller_net_amount_minor' => $this->sellerNetAmountMinor,
            'amount_due_minor' => $this->amountDueMinor,
            'amount_paid_minor' => $this->amountPaidMinor,
            'remaining_amount_minor' => $this->remainingAmountMinor,
            'currency_code' => $this->currencyCode,
            'payment_due_at' => $this->paymentDueAt,
            'handover_due_at' => $this->handoverDueAt,
            'paid_at' => $this->paidAt,
            'previous_settlement_id' => $this->previousSettlementId,
            'winner_reassignment_id' => $this->winnerReassignmentId,
        ];
    }

    public function toArray(): array
    {
        return $this->toPersistenceArray();
    }
}
