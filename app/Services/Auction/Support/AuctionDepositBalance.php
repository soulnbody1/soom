<?php

declare(strict_types=1);

namespace App\Services\Auction\Support;

use App\Domain\Auction\Enums\RefundTransactionStatus;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\RefundTransaction;
use Illuminate\Support\Collection;

final readonly class AuctionDepositBalance
{
    public function __construct(
        public int $requiredMinor,
        public int $heldMinor,
        public int $appliedMinor,
        public int $refundedMinor,
        public int $forfeitedMinor,
        public int $pendingRefundMinor,
        public int $refundableMinor,
    ) {}

    public static function for(AuctionDeposit $deposit): self
    {
        $refunds = $deposit->relationLoaded('refunds') ? $deposit->refunds : collect();
        $held = (int) $deposit->held_amount_minor;

        return new self(
            (int) $deposit->required_amount_minor,
            $held,
            (int) $deposit->applied_amount_minor,
            (int) $deposit->refunded_amount_minor,
            (int) $deposit->forfeited_amount_minor,
            self::pendingAmount($refunds),
            max(0, $held - DepositRefundAllocation::reservedHeldAmount($refunds)),
        );
    }

    private static function pendingAmount(Collection $refunds): int
    {
        return (int) $refunds
            ->filter(fn (RefundTransaction $refund): bool => in_array($refund->status, [
                RefundTransactionStatus::Pending,
                RefundTransactionStatus::Processing,
            ], true))
            ->sum(fn (RefundTransaction $refund): int => (int) $refund->amount_minor);
    }
}
