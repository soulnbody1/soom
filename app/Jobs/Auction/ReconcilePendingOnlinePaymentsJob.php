<?php

declare(strict_types=1);

namespace App\Jobs\Auction;

use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\PaymentTransaction;
use App\Repositories\Auction\AuctionPaymentRepository;
use App\Services\Auction\Actions\RefreshOnlinePaymentAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

final class ReconcilePendingOnlinePaymentsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(AuctionPaymentRepository $payments, RefreshOnlinePaymentAction $refresh): void
    {
        $olderThan = max(30, (int) config('auction.payments.reconcile_after_seconds', 300));
        $batch = max(1, (int) config('auction.payments.reconcile_batch', 100));

        $payments->pendingOnlineTransactionsQuery($olderThan)
            ->limit($batch)
            ->get()
            ->each(function (PaymentTransaction $transaction) use ($refresh): void {
                try {
                    $refresh->execute($transaction, true);
                } catch (AuctionException $exception) {
                    Log::warning('Online payment reconciliation skipped a transaction.', [
                        'payment_transaction_id' => $transaction->id,
                        'provider' => $transaction->provider,
                        'error_code' => $exception->getErrorCode(),
                    ]);
                }
            });
    }
}
