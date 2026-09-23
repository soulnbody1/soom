<?php

declare(strict_types=1);

namespace App\Jobs\Auction;

use App\Contracts\Market\RunsAcrossMarkets;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Jobs\Concerns\HasMarketJobContext;
use App\Models\Auction\RefundTransaction;
use App\Repositories\Auction\AuctionRefundRepository;
use App\Services\Auction\Actions\ProcessAuctionRefundAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

final class ProcessPendingAuctionRefundsJob implements RunsAcrossMarkets, ShouldQueue
{
    use HasMarketJobContext, Queueable;

    public int $tries = 1;

    public function handle(AuctionRefundRepository $refunds, ProcessAuctionRefundAction $processRefund): void
    {
        // Concurrency is guarded by the lease + processing token acquired inside
        // ProcessAuctionRefundAction (see claimProcessingLease). Row locks here
        // would span multiple lazyById queries with no enclosing transaction, so
        // they would be released immediately and provide no real protection.
        $refunds->dueForProcessingQuery()
            ->select('id')
            ->lazyById(100)
            ->each(function (RefundTransaction $refund) use ($processRefund): void {
                try {
                    $processRefund->execute($refund);
                } catch (AuctionException $exception) {
                    if ($this->isExpectedRaceCondition($exception)) {
                        // Another worker claimed/completed it, or it is not yet due.
                        return;
                    }

                    Log::warning('Unexpected auction refund processing failure.', [
                        'refund_id' => $refund->id,
                        'message' => $exception->getMessage(),
                    ]);
                }
            });
    }

    private function isExpectedRaceCondition(AuctionException $exception): bool
    {
        return in_array($exception->getMessage(), [
            __('auction.errors.refund_not_processable'),
            __('auction.errors.refund_lease_already_acquired'),
            __('auction.errors.refund_processing_token_mismatch'),
        ], true);
    }
}
