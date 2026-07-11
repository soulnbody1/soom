<?php

declare(strict_types=1);

namespace App\Jobs\Auction;

use App\Application\Auction\Actions\RefundAuctionDepositAction;
use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Models\Auction\AuctionDeposit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class RefundPendingAuctionDepositsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function handle(RefundAuctionDepositAction $refund): void
    {
        AuctionDeposit::where('status', AuctionDepositStatus::RefundPending->value)
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->each(fn (AuctionDeposit $deposit) => $refund->execute($deposit, 'auction settlement refund'));
    }
}
