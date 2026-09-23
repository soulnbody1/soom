<?php

declare(strict_types=1);

namespace App\Jobs\Auction;

use App\Contracts\Market\RunsAcrossMarkets;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\PaymentSubmissionStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Jobs\Concerns\HasMarketJobContext;
use App\Models\Auction\Auction;
use App\Services\Auction\Actions\ExpireSellerDepositDeadlineAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

final class ExpireSellerDepositDeadlinesJob implements RunsAcrossMarkets, ShouldQueue
{
    use HasMarketJobContext, Queueable;

    public int $tries = 3;

    public function handle(ExpireSellerDepositDeadlineAction $expire): void
    {
        $now = Carbon::now();

        Auction::query()
            ->where('status', AuctionStatus::AwaitingSellerDeposit->value)
            ->whereNotNull('seller_deposit_due_at')
            ->where('seller_deposit_due_at', '<', $now)
            ->where(fn ($query) => $query
                ->whereNull('seller_deposit_deadline_processed_at')
                ->orWhere('seller_deposit_deadline_processed_at', '<', $now->copy()->subMinutes($this->leaseMinutes())))
            ->whereDoesntHave('paymentSubmissions', fn ($query) => $query
                ->where('purpose', 'seller_deposit')
                ->where('status', PaymentSubmissionStatus::PendingReview->value))
            ->lazyById(100)
            ->each(function (Auction $auction) use ($expire): void {
                try {
                    $expire->execute($auction);
                } catch (AuctionException) {
                }
            });
    }

    private function leaseMinutes(): int
    {
        return max(1, (int) config('auction.deadlines.seller_deposit_expiry_lease_minutes', 15));
    }
}
