<?php

declare(strict_types=1);

namespace App\Services\Auction\Support;

use App\Domain\Auction\Enums\RefundTransactionStatus;
use App\Domain\Auction\Enums\SettlementStatus;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\RefundTransaction;
use Illuminate\Support\Collection;

final readonly class DepositRefundAllocation
{
    public function __construct(
        public int $heldAmountMinor,
        public int $appliedAmountMinor,
    ) {}

    public static function calculateRefundableDepositAmount(
        AuctionDeposit $deposit,
        int $capturedAmountMinor,
        Collection $reservedRefunds,
        Collection $relatedSettlements,
        ?int $exceptRefundId = null
    ): self {
        $reservedHeld = self::reservedAmount($reservedRefunds, 'held_refund_amount_minor', $exceptRefundId);
        $reservedApplied = self::reservedAmount($reservedRefunds, 'applied_refund_amount_minor', $exceptRefundId);

        $held = max(0, (int) $deposit->held_amount_minor - $reservedHeld);
        $applied = self::hasActiveAppliedSettlement($relatedSettlements)
            ? 0
            : max(0, (int) $deposit->applied_amount_minor - $reservedApplied);

        $capturedRemaining = max(
            0,
            $capturedAmountMinor
                - (int) $deposit->refunded_amount_minor
                - (int) $deposit->forfeited_amount_minor
                - $reservedHeld
                - $reservedApplied
        );

        if ($held + $applied <= $capturedRemaining) {
            return new self($held, $applied);
        }

        $held = min($held, $capturedRemaining);
        $applied = min($applied, max(0, $capturedRemaining - $held));

        return new self($held, $applied);
    }

    public static function fromRefund(RefundTransaction $refund): self
    {
        $held = (int) ($refund->held_refund_amount_minor ?? 0);
        $applied = (int) ($refund->applied_refund_amount_minor ?? 0);

        if ($held === 0 && $applied === 0 && $refund->deposit_id) {
            $held = (int) $refund->amount_minor;
        }

        return new self($held, $applied);
    }

    public function totalAmountMinor(): int
    {
        return $this->heldAmountMinor + $this->appliedAmountMinor;
    }

    public function isEmpty(): bool
    {
        return $this->totalAmountMinor() <= 0;
    }

    public static function hasActiveAppliedSettlement(Collection $relatedSettlements): bool
    {
        return $relatedSettlements->contains(function ($settlement): bool {
            $status = $settlement->status instanceof SettlementStatus
                ? $settlement->status
                : SettlementStatus::from((string) $settlement->status);

            return ! in_array($status, [
                SettlementStatus::Cancelled,
                SettlementStatus::Defaulted,
            ], true);
        });
    }

    private static function reservedAmount(Collection $refunds, string $column, ?int $exceptRefundId): int
    {
        return (int) $refunds
            ->reject(fn (RefundTransaction $refund): bool => $exceptRefundId !== null && $refund->id === $exceptRefundId)
            ->filter(fn (RefundTransaction $refund): bool => in_array($refund->status, [
                RefundTransactionStatus::Pending,
                RefundTransactionStatus::Processing,
            ], true))
            ->sum(fn (RefundTransaction $refund): int => (int) ($refund->{$column} ?? 0));
    }
}
