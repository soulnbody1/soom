<?php

declare(strict_types=1);

namespace App\Repositories\Auction;

use App\Domain\Auction\Enums\RefundTransactionStatus;
use App\Models\Auction\RefundTransaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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
        $provider = (string) $uniqueAttributes['provider'];
        $baseKey = (string) $uniqueAttributes['idempotency_key'];

        $existing = RefundTransaction::where('provider', $provider)
            ->where(function (Builder $query) use ($baseKey): void {
                $query->where('idempotency_key', $baseKey)
                    ->orWhere('idempotency_key', 'like', "{$baseKey}:retry:%");
            })
            ->where('status', '!=', RefundTransactionStatus::Cancelled->value)
            ->orderBy('id')
            ->first();

        if ($existing) {
            return $existing;
        }

        if (RefundTransaction::where($uniqueAttributes)->where('status', RefundTransactionStatus::Cancelled->value)->exists()) {
            $uniqueAttributes['idempotency_key'] = $this->nextRetryKey($provider, $baseKey);
        }

        return RefundTransaction::create($uniqueAttributes + $defaults);
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

    public function hasOutstandingForDeposit(int $depositId, int $exceptRefundId): bool
    {
        return RefundTransaction::where('deposit_id', $depositId)
            ->whereKeyNot($exceptRefundId)
            ->whereIn('status', [
                RefundTransactionStatus::Pending->value,
                RefundTransactionStatus::Processing->value,
                RefundTransactionStatus::Failed->value,
                RefundTransactionStatus::ManualReview->value,
            ])
            ->lockForUpdate()
            ->first() !== null;
    }

    public function paginateForAdmin(array $filters, int $perPage): LengthAwarePaginator
    {
        return RefundTransaction::with([
            'auction:id,public_id,title',
            'user:id,name',
        ])
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['auction_id'] ?? null, function (Builder $query, string $auctionId): void {
                if (ctype_digit($auctionId)) {
                    $query->where('auction_id', (int) $auctionId);

                    return;
                }

                $query->whereHas('auction', fn (Builder $auction) => $auction->where('public_id', $auctionId));
            })
            ->when($filters['user_id'] ?? null, fn (Builder $query, $userId) => $query->where('user_id', (int) $userId))
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();
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

    private function nextRetryKey(string $provider, string $baseKey): string
    {
        $attempt = 1;

        do {
            $key = "{$baseKey}:retry:{$attempt}";
            $attempt++;
        } while (RefundTransaction::where('provider', $provider)->where('idempotency_key', $key)->exists());

        return $key;
    }
}
