<?php

declare(strict_types=1);

namespace App\Jobs\Auction;

use App\Contracts\Market\RunsAcrossMarkets;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\SettlementStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Jobs\Concerns\HasMarketJobContext;
use App\Models\Auction\AuctionSettlement;
use App\Services\Auction\Actions\SendWinnerPaymentRemindersAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

final class SendWinnerPaymentRemindersJob implements RunsAcrossMarkets, ShouldQueue
{
    use HasMarketJobContext, Queueable;

    public int $tries = 3;

    public function handle(SendWinnerPaymentRemindersAction $reminders): void
    {
        $now = Carbon::now();

        AuctionSettlement::query()
            ->where('status', SettlementStatus::PaymentPending->value)
            ->where('is_current', true)
            ->whereNotNull('payment_due_at')
            ->where(fn ($query) => $query
                ->whereNull('payment_grace_ends_at')
                ->orWhere('payment_grace_ends_at', '>', $now))
            ->whereHas('auction', fn ($query) => $query->where('status', AuctionStatus::PaymentPending->value))
            ->lazyById(100)
            ->each(function (AuctionSettlement $settlement) use ($reminders): void {
                try {
                    $reminders->execute($settlement);
                } catch (AuctionException) {
                }
            });
    }
}
