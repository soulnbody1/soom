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
            'terminal_seller_deposits_held_without_active_need' => $this->terminalHeldSellerDepositCount(),
            'seller_deposits_resolved_without_terminal_or_policy_reason' => $this->sellerDepositsResolvedWithoutTerminalReasonCount(),
            'cancelled_auctions_financially_unbalanced' => $this->cancelledAuctionsFinanciallyUnbalancedCount(),
            'cancelled_current_settlements_active' => $this->cancelledCurrentSettlementsActiveCount(),
            'cancelled_pending_payment_submissions' => $this->cancelledPendingPaymentSubmissionsCount(),
            'cancelled_successful_payments_without_refund_or_disposition' => $this->cancelledSuccessfulPaymentsWithoutRefundOrDispositionCount(),
            'cancelled_bidder_deposits_held' => $this->cancelledBidderDepositsHeldCount(),
            'cancelled_seller_deposits_without_disposition' => $this->cancelledSellerDepositsWithoutDispositionCount(),
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

    private function terminalHeldSellerDepositCount(): int
    {
        return (int) DB::table('auction_deposits')
            ->join('auctions', 'auctions.id', '=', 'auction_deposits.auction_id')
            ->where('auction_deposits.type', 'seller')
            ->where('auction_deposits.status', AuctionDepositStatus::Held->value)
            ->whereIn('auctions.status', [
                AuctionStatus::Completed->value,
                AuctionStatus::Unsold->value,
                AuctionStatus::Cancelled->value,
            ])
            ->where(function ($query): void {
                $query->whereNull('auction_deposits.hold_reason')
                    ->orWhereNotIn('auction_deposits.hold_reason', [
                        'seller_deposit_manual_review',
                        'seller_deposit_keep_held',
                    ]);
            })
            ->count();
    }

    private function sellerDepositsResolvedWithoutTerminalReasonCount(): int
    {
        return (int) DB::table('auction_deposits')
            ->join('auctions', 'auctions.id', '=', 'auction_deposits.auction_id')
            ->where('auction_deposits.type', 'seller')
            ->whereIn('auction_deposits.status', [
                AuctionDepositStatus::RefundPending->value,
                AuctionDepositStatus::Refunded->value,
                AuctionDepositStatus::Forfeited->value,
            ])
            ->whereNotIn('auctions.status', [
                AuctionStatus::Rejected->value,
                AuctionStatus::Completed->value,
                AuctionStatus::Unsold->value,
                AuctionStatus::Cancelled->value,
                AuctionStatus::Defaulted->value,
                AuctionStatus::Disputed->value,
            ])
            ->count();
    }

    private function cancelledAuctionsFinanciallyUnbalancedCount(): int
    {
        return $this->cancelledCurrentSettlementsActiveCount()
            + $this->cancelledPendingPaymentSubmissionsCount()
            + $this->cancelledSuccessfulPaymentsWithoutRefundOrDispositionCount()
            + $this->cancelledBidderDepositsHeldCount()
            + $this->cancelledSellerDepositsWithoutDispositionCount();
    }

    private function cancelledCurrentSettlementsActiveCount(): int
    {
        return (int) DB::table('auction_settlements')
            ->join('auctions', 'auctions.id', '=', 'auction_settlements.auction_id')
            ->where('auctions.status', AuctionStatus::Cancelled->value)
            ->where('auction_settlements.current_marker', 1)
            ->count();
    }

    private function cancelledPendingPaymentSubmissionsCount(): int
    {
        return (int) DB::table('payment_submissions')
            ->join('auctions', 'auctions.id', '=', 'payment_submissions.auction_id')
            ->where('auctions.status', AuctionStatus::Cancelled->value)
            ->where('payment_submissions.status', PaymentSubmissionStatus::PendingReview->value)
            ->count();
    }

    private function cancelledSuccessfulPaymentsWithoutRefundOrDispositionCount(): int
    {
        return (int) DB::table('payment_transactions')
            ->join('auctions', 'auctions.id', '=', 'payment_transactions.auction_id')
            ->join('payment_submissions', 'payment_submissions.id', '=', 'payment_transactions.payment_submission_id')
            ->leftJoin('auction_deposits', 'auction_deposits.id', '=', 'payment_submissions.deposit_id')
            ->leftJoin('refund_transactions', function ($join): void {
                $join->on('refund_transactions.payment_transaction_id', '=', 'payment_transactions.id')
                    ->whereIn('refund_transactions.status', [
                        RefundTransactionStatus::Pending->value,
                        RefundTransactionStatus::Processing->value,
                        RefundTransactionStatus::Succeeded->value,
                    ]);
            })
            ->where('auctions.status', AuctionStatus::Cancelled->value)
            ->where('payment_transactions.status', 'succeeded')
            ->whereNull('refund_transactions.id')
            ->where(function ($query): void {
                $query->whereNull('auction_deposits.id')
                    ->orWhere(function ($query): void {
                        $query->where('auction_deposits.status', '!=', AuctionDepositStatus::Forfeited->value)
                            ->where(function ($query): void {
                                $query->whereNull('auction_deposits.hold_reason')
                                    ->orWhereNotIn('auction_deposits.hold_reason', [
                                        'seller_deposit_manual_review',
                                        'seller_deposit_keep_held',
                                    ]);
                            });
                    });
            })
            ->count();
    }

    private function cancelledBidderDepositsHeldCount(): int
    {
        return (int) DB::table('auction_deposits')
            ->join('auctions', 'auctions.id', '=', 'auction_deposits.auction_id')
            ->where('auctions.status', AuctionStatus::Cancelled->value)
            ->where('auction_deposits.type', 'bidder')
            ->where('auction_deposits.status', AuctionDepositStatus::Held->value)
            ->count();
    }

    private function cancelledSellerDepositsWithoutDispositionCount(): int
    {
        return (int) DB::table('auction_deposits')
            ->join('auctions', 'auctions.id', '=', 'auction_deposits.auction_id')
            ->where('auctions.status', AuctionStatus::Cancelled->value)
            ->where('auction_deposits.type', 'seller')
            ->where(function ($query): void {
                $query->where(function ($query): void {
                    $query->where('auction_deposits.status', AuctionDepositStatus::Held->value)
                        ->whereNull('auction_deposits.hold_reason');
                })->orWhere(function ($query): void {
                    $query->whereIn('auction_deposits.status', [
                        AuctionDepositStatus::PendingSubmission->value,
                        AuctionDepositStatus::PendingReview->value,
                    ]);
                });
            })
            ->count();
    }
}
