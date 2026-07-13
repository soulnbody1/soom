<?php

declare(strict_types=1);

namespace App\Jobs\Auction;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\AuctionDeposit;
use App\Services\Auction\Actions\RefundAuctionDepositAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class RefundPendingAuctionDepositsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function handle(RefundAuctionDepositAction $refund): void
    {
        AuctionDeposit::where('status', AuctionDepositStatus::RefundPending->value)
            ->lazyById(100)
            ->take(100)
            ->each(function (AuctionDeposit $deposit) use ($refund): void {
                try {
                    $refund->execute($deposit, 'auction settlement refund');
                } catch (AuctionException) {
                    // Another worker may have already refunded or made it ineligible.
                }
            });
    }
}
