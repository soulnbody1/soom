<?php

declare(strict_types=1);

namespace App\Jobs\Auction;

use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\RefundTransaction;
use App\Repositories\Auction\AuctionRefundRepository;
use App\Services\Auction\Actions\ProcessAuctionRefundAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

final class ProcessPendingAuctionRefundsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(AuctionRefundRepository $refunds, ProcessAuctionRefundAction $processRefund): void
    {
        $query = $refunds->dueForProcessingQuery()->select('id');

        if (DB::connection()->getDriverName() === 'mysql' && method_exists($query->getQuery(), 'skipLocked')) {
            $query->lockForUpdate()->skipLocked();
        }

        $query
            ->lazyById(100)
            ->each(function (RefundTransaction $refund) use ($processRefund): void {
                try {
                    $processRefund->execute($refund);
                } catch (AuctionException) {
                    // Another worker may have claimed or completed it first.
                }
            });
    }
}
