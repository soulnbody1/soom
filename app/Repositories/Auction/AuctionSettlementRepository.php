<?php

declare(strict_types=1);

namespace App\Repositories\Auction;

use App\DTO\Auction\CreateSettlementDTO;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\AuctionSettlement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class AuctionSettlementRepository
{
    /**
     * Lock the current settlement for an auction.
     */
    public function lockSettlement(int $auctionId): AuctionSettlement
    {
        return AuctionSettlement::where('auction_id', $auctionId)
            ->where('current_marker', 1)
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function lockCurrentSettlementForPayment(int $auctionId): ?AuctionSettlement
    {
        return AuctionSettlement::where('auction_id', $auctionId)
            ->where('current_marker', 1)
            ->lockForUpdate()
            ->first();
    }

    public function lockById(int $settlementId): AuctionSettlement
    {
        return AuctionSettlement::whereKey($settlementId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Create a new settlement record.
     * Used by FinalizeAuctionAction.
     */
    public function createSettlement(CreateSettlementDTO $dto): AuctionSettlement
    {
        $attributes = $dto->toPersistenceArray();

        $current = AuctionSettlement::where('auction_id', $attributes['auction_id'])
            ->where('current_marker', 1)
            ->lockForUpdate()
            ->first();

        $sequence = ((int) AuctionSettlement::where('auction_id', $attributes['auction_id'])->max('sequence_number')) + 1;

        if ($current) {
            $current->forceFill([
                'is_current' => false,
                'current_marker' => null,
                'superseded_at' => Carbon::now(),
            ])->save();
        }

        $amountDue = (int) $attributes['amount_due_minor'];
        $amountPaid = (int) ($attributes['amount_paid_minor'] ?? 0);

        return AuctionSettlement::create(array_merge($attributes, [
            'sequence_number' => $sequence,
            'is_current' => true,
            'current_marker' => 1,
            'previous_settlement_id' => $attributes['previous_settlement_id'] ?? $current?->id,
            'amount_paid_minor' => $amountPaid,
            'remaining_amount_minor' => max(0, $amountDue - $amountPaid),
        ]));
    }

    public function closeAsHistorical(AuctionSettlement $settlement, string $reason, ?int $overriddenBy = null, ?string $overrideReason = null, bool $automatic = false): void
    {
        $now = Carbon::now();

        $settlement->forceFill([
            'is_current' => false,
            'current_marker' => null,
            'status' => \App\Domain\Auction\Enums\SettlementStatus::Defaulted,
            'defaulted_at' => $settlement->defaulted_at ?? $now,
            'default_reason' => $settlement->default_reason ?? $reason,
            'auto_defaulted' => $automatic,
            'superseded_at' => $settlement->superseded_at ?? $now,
            'overridden_by' => $overriddenBy,
            'overridden_at' => $overriddenBy ? $now : null,
            'override_reason' => $overrideReason,
            'original_payment_due_at' => $settlement->original_payment_due_at ?? $settlement->payment_due_at,
        ]);
        $this->save($settlement);
    }

    public function closeAsCancelled(AuctionSettlement $settlement, string $reason, ?int $actorId = null): void
    {
        $now = Carbon::now();

        $settlement->forceFill([
            'is_current' => false,
            'current_marker' => null,
            'status' => \App\Domain\Auction\Enums\SettlementStatus::Cancelled,
            'cancelled_at' => $settlement->cancelled_at ?? $now,
            'cancelled_by' => $settlement->cancelled_by ?? $actorId,
            'cancel_reason' => $settlement->cancel_reason ?? $reason,
            'superseded_at' => $settlement->superseded_at ?? $now,
        ]);
        $this->save($settlement);
    }

    public function lockForDepositRefund(AuctionDeposit $deposit): Collection
    {
        return AuctionSettlement::where('auction_id', $deposit->auction_id)
            ->where('winner_id', $deposit->user_id)
            ->where('deposit_applied_minor', '>', 0)
            ->lockForUpdate()
            ->get();
    }

    /**
     * Save settlement model after in-memory changes.
     */
    public function save(AuctionSettlement $settlement): void
    {
        $settlement->save();
    }
}
