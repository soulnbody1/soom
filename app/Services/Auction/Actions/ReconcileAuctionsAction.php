<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\OutboxStatus;
use App\Domain\Auction\Enums\PaymentSubmissionStatus;
use App\Domain\Auction\Enums\RefundTransactionStatus;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\OutboxMessage;
use App\Models\Auction\PaymentSubmission;
use App\Models\Auction\RefundTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ReconcileAuctionsAction
{
    public function execute(): array
    {
        $report = [
            'ended_without_settlement' => Auction::where('status', AuctionStatus::Ended->value)->doesntHave('settlement')->count(),
            'payment_submissions_pending' => PaymentSubmission::where('status', PaymentSubmissionStatus::PendingReview->value)->count(),
            'deposits_refund_pending' => AuctionDeposit::where('status', AuctionDepositStatus::RefundPending->value)->count(),
            'terminal_non_winner_deposits_held_without_active_need' => $this->terminalHeldNonWinnerDepositCount(),
            'refunds_failed' => RefundTransaction::where('status', RefundTransactionStatus::Failed->value)->count(),
            'outbox_pending' => OutboxMessage::where('status', OutboxStatus::Pending->value)->count(),
        ];

        Log::info('Auction reconciliation report.', $report);

        return $report;
    }

    private function terminalHeldNonWinnerDepositCount(): int
    {
        return (int) DB::table('auction_deposits')
            ->join('auctions', 'auctions.id', '=', 'auction_deposits.auction_id')
            ->leftJoin('auction_bids as winning_bids', 'winning_bids.id', '=', 'auctions.winning_bid_id')
            ->leftJoin('auction_settlements as current_settlements', function ($join): void {
                $join->on('current_settlements.auction_id', '=', 'auctions.id')
                    ->where('current_settlements.current_marker', '=', 1);
            })
            ->where('auction_deposits.type', 'bidder')
            ->where('auction_deposits.status', AuctionDepositStatus::Held->value)
            ->whereIn('auctions.status', [
                AuctionStatus::Completed->value,
                AuctionStatus::Unsold->value,
                AuctionStatus::Cancelled->value,
                AuctionStatus::Defaulted->value,
            ])
            ->where(function ($query): void {
                $query->whereNull('winning_bids.id')
                    ->orWhereColumn('winning_bids.bidder_id', '!=', 'auction_deposits.user_id');
            })
            ->where(function ($query): void {
                $query->whereNull('current_settlements.id')
                    ->orWhereColumn('current_settlements.winner_id', '!=', 'auction_deposits.user_id');
            })
            ->count();
    }
}
