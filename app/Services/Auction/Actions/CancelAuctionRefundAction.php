<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\RefundTransactionStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\RefundTransaction;
use App\Models\User;
use App\Repositories\Auction\AuctionDepositRepository;
use App\Repositories\Auction\AuctionRefundRepository;
use App\Services\Auction\Support\AuctionAudit;
use App\Services\Auction\Support\AuctionTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

final class CancelAuctionRefundAction
{
    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionAudit $audit,
        private readonly AuctionDepositRepository $deposits,
        private readonly AuctionRefundRepository $refunds,
    ) {}

    public function execute(RefundTransaction $refund, User $actor, string $reason): RefundTransaction
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw AuctionException::domain('refund_cancellation_reason_required');
        }

        return $this->transaction->run(function () use ($refund, $actor, $reason): RefundTransaction {
            $refund = $this->refunds->lockForConfirmation($refund->id);

            if (! Gate::forUser($actor)->allows('cancel', $refund)) {
                throw AuctionException::domain('refund_cancellation_unauthorized');
            }

            if ($refund->status === RefundTransactionStatus::Cancelled) {
                $this->restoreDepositStatus($refund);

                return $refund->refresh();
            }

            if ($refund->status === RefundTransactionStatus::Succeeded) {
                throw AuctionException::domain('refund_cancellation_not_allowed');
            }

            if ($refund->status === RefundTransactionStatus::Processing) {
                throw AuctionException::domain('refund_cancellation_not_allowed');
            }

            if (! in_array($refund->status, [
                RefundTransactionStatus::Pending,
                RefundTransactionStatus::Failed,
                RefundTransactionStatus::ManualReview,
            ], true)) {
                throw AuctionException::domain('refund_cancellation_not_allowed');
            }

            $refund->forceFill([
                'status' => RefundTransactionStatus::Cancelled,
                'cancelled_by' => $actor->id,
                'cancelled_at' => Carbon::now(),
                'cancellation_reason' => $reason,
                'processing_token' => null,
                'lease_expires_at' => null,
                'next_retry_at' => null,
            ]);
            $this->refunds->save($refund);

            $this->restoreDepositStatus($refund);

            $this->audit->log('auction.refund_cancelled', $refund->auction, $actor->id, 'admin', [
                'refund_public_id' => $refund->public_id,
                'reason' => $reason,
            ]);
            $this->audit->outbox('auction.refund_cancelled', $refund->auction, [
                'refund_public_id' => $refund->public_id,
                'reason' => $reason,
                'status' => RefundTransactionStatus::Cancelled->value,
            ]);

            return $refund->refresh();
        });
    }

    private function restoreDepositStatus(RefundTransaction $refund): void
    {
        if (! $refund->deposit_id) {
            return;
        }

        $deposit = $this->deposits->lockForRefund((int) $refund->deposit_id);

        if ($this->refunds->hasOutstandingForDeposit($deposit->id, $refund->id)) {
            if ($deposit->status !== AuctionDepositStatus::RefundPending) {
                $deposit->forceFill(['status' => AuctionDepositStatus::RefundPending]);
                $this->deposits->save($deposit);
            }

            return;
        }

        $status = $this->statusFromBuckets($deposit);

        if ($deposit->status !== $status) {
            $deposit->forceFill(['status' => $status]);
            $this->deposits->save($deposit);
        }
    }

    private function statusFromBuckets(AuctionDeposit $deposit): AuctionDepositStatus
    {
        if ((int) $deposit->held_amount_minor > 0) {
            return AuctionDepositStatus::Held;
        }

        if ((int) $deposit->applied_amount_minor > 0) {
            return AuctionDepositStatus::AppliedToSettlement;
        }

        if ((int) $deposit->refunded_amount_minor >= (int) $deposit->required_amount_minor) {
            return AuctionDepositStatus::Refunded;
        }

        if ((int) $deposit->forfeited_amount_minor >= (int) $deposit->required_amount_minor) {
            return AuctionDepositStatus::Forfeited;
        }

        if ((int) $deposit->refunded_amount_minor > 0) {
            return AuctionDepositStatus::Refunded;
        }

        if ((int) $deposit->forfeited_amount_minor > 0) {
            return AuctionDepositStatus::Forfeited;
        }

        return AuctionDepositStatus::Held;
    }
}
