<?php

declare(strict_types=1);

namespace App\Jobs\Auction;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\SettlementStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\AuctionSettlement;
use App\Services\Auction\Actions\SendHandoverRemindersAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SendHandoverRemindersJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function handle(SendHandoverRemindersAction $reminders): void
    {
        AuctionSettlement::query()
            ->whereIn('status', [SettlementStatus::Paid->value, SettlementStatus::HandoverPending->value])
            ->where('is_current', true)
            ->whereNotNull('handover_due_at')
            ->whereNull('handover_completed_at')
            ->whereHas('auction', fn ($query) => $query->where('status', AuctionStatus::HandoverPending->value))
            ->lazyById(100)
            ->each(function (AuctionSettlement $settlement) use ($reminders): void {
                try {
                    $reminders->execute($settlement);
                } catch (AuctionException) {
                }
            });
    }
}
