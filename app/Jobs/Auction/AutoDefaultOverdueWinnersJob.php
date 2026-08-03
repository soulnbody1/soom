<?php

declare(strict_types=1);

namespace App\Jobs\Auction;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\PaymentSubmissionStatus;
use App\Domain\Auction\Enums\SettlementStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\AuctionSettlement;
use App\Services\Auction\Actions\MarkWinnerDefaultedAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

final class AutoDefaultOverdueWinnersJob implements ShouldQueue
{
    use Queueable;

    public const REASON = 'winner_payment_deadline_expired';

    public int $tries = 3;

    public function handle(MarkWinnerDefaultedAction $markDefaulted): void
    {
        $now = Carbon::now();

        AuctionSettlement::query()
            ->with('auction')
            ->where('status', SettlementStatus::PaymentPending->value)
            ->where('is_current', true)
            ->whereNotNull('payment_grace_ends_at')
            ->where('payment_grace_ends_at', '<', $now)
            ->whereHas('auction', fn ($query) => $query->where('status', AuctionStatus::PaymentPending->value))
            ->whereDoesntHave('paymentSubmissions', fn ($query) => $query->where('status', PaymentSubmissionStatus::PendingReview->value))
            ->lazyById(100)
            ->each(function (AuctionSettlement $settlement) use ($markDefaulted): void {
                $auction = $settlement->auction;

                if (! $auction) {
                    return;
                }

                try {
                    $markDefaulted->execute(
                        auction: $auction,
                        adminId: null,
                        reason: self::REASON,
                        reassignToNext: true,
                        automatic: true,
                    );
                } catch (AuctionException) {
                }
            });
    }
}
