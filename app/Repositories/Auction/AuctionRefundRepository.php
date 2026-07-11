<?php

declare(strict_types=1);

namespace App\Repositories\Auction;

use App\Models\Auction\RefundTransaction;

final class AuctionRefundRepository
{
    /**
     * Create or find a refund transaction (idempotent).
     * Used by RefundAuctionDepositAction.
     */
    public function firstOrCreateRefund(array $uniqueAttributes, array $defaults): RefundTransaction
    {
        return RefundTransaction::firstOrCreate($uniqueAttributes, $defaults);
    }

    /**
     * Lock refund transaction for status update.
     * Used by RefundAuctionDepositAction::confirmSucceeded.
     */
    public function lockForConfirmation(int $refundId): RefundTransaction
    {
        return RefundTransaction::whereKey($refundId)->lockForUpdate()->firstOrFail();
    }

    public function providerRefundIdExists(string $provider, string $providerRefundId, int $exceptRefundId): bool
    {
        return RefundTransaction::where('provider', $provider)
            ->where('provider_refund_id', $providerRefundId)
            ->whereKeyNot($exceptRefundId)
            ->exists();
    }

    /**
     * Save refund model after in-memory changes.
     */
    public function save(RefundTransaction $refund): void
    {
        $refund->save();
    }
}
