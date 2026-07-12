<?php

declare(strict_types=1);

namespace App\Repositories\Auction;

use App\Domain\Auction\Enums\RefundTransactionStatus;
use App\Models\Auction\RefundTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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

    public function lockActiveOrSucceededForPayment(int $paymentTransactionId): Collection
    {
        return RefundTransaction::where('payment_transaction_id', $paymentTransactionId)
            ->whereIn('status', [
                RefundTransactionStatus::Pending->value,
                RefundTransactionStatus::Processing->value,
                RefundTransactionStatus::Succeeded->value,
            ])
            ->lockForUpdate()
            ->get();
    }

    public function lockActiveOrSucceededForDeposit(int $depositId): Collection
    {
        return RefundTransaction::where('deposit_id', $depositId)
            ->whereIn('status', [
                RefundTransactionStatus::Pending->value,
                RefundTransactionStatus::Processing->value,
                RefundTransactionStatus::Succeeded->value,
            ])
            ->lockForUpdate()
            ->get();
    }

    public function providerRefundIdExists(string $provider, string $providerRefundId, int $exceptRefundId): bool
    {
        return RefundTransaction::where('provider', $provider)
            ->where('provider_refund_id', $providerRefundId)
            ->whereKeyNot($exceptRefundId)
            ->exists();
    }

    public function succeededAmountForPayment(int $paymentTransactionId): int
    {
        return (int) RefundTransaction::where('payment_transaction_id', $paymentTransactionId)
            ->where('status', RefundTransactionStatus::Succeeded->value)
            ->sum('amount_minor');
    }

    public function dueForProcessingQuery(): Builder
    {
        $now = Carbon::now();

        return RefundTransaction::query()
            ->where(function (Builder $query) use ($now): void {
                $query->where('status', RefundTransactionStatus::Pending->value)
                    ->orWhere(function (Builder $query) use ($now): void {
                        $query->where('status', RefundTransactionStatus::Failed->value)
                            ->where(function (Builder $query) use ($now): void {
                                $query->whereNull('next_retry_at')
                                    ->orWhere('next_retry_at', '<=', $now);
                            });
                    })
                    ->orWhere(function (Builder $query) use ($now): void {
                        $query->where('status', RefundTransactionStatus::Processing->value)
                            ->where('lease_expires_at', '<=', $now);
                    });
            })
            ->orderBy('id');
    }

    /**
     * Save refund model after in-memory changes.
     */
    public function save(RefundTransaction $refund): void
    {
        $refund->save();
    }
}
