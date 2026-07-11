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
use Illuminate\Support\Facades\Log;

final class ReconcileAuctionsAction
{
    public function execute(): array
    {
        $report = [
            'ended_without_settlement' => Auction::where('status', AuctionStatus::Ended->value)->doesntHave('settlement')->count(),
            'payment_submissions_pending' => PaymentSubmission::where('status', PaymentSubmissionStatus::PendingReview->value)->count(),
            'deposits_refund_pending' => AuctionDeposit::where('status', AuctionDepositStatus::RefundPending->value)->count(),
            'refunds_failed' => RefundTransaction::where('status', RefundTransactionStatus::Failed->value)->count(),
            'outbox_pending' => OutboxMessage::where('status', OutboxStatus::Pending->value)->count(),
        ];

        Log::info('Auction reconciliation report.', $report);

        return $report;
    }
}
