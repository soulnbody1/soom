<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\RefundTransactionStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\RefundTransaction;
use App\Repositories\Auction\AuctionDepositRepository;
use App\Repositories\Auction\AuctionPaymentRepository;
use App\Repositories\Auction\AuctionRefundRepository;
use App\Repositories\Auction\AuctionSettlementRepository;
use App\Services\Auction\Support\AuctionAudit;
use App\Services\Auction\Support\AuctionRefundCompletion;
use App\Services\Auction\Support\AuctionTransaction;
use App\Services\Auction\Support\DepositRefundAllocation;
use App\Services\Auction\Support\FinancialObligationKey;

final class RefundAuctionDepositAction
{
    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionAudit $audit,
        private readonly AuctionDepositRepository $deposits,
        private readonly AuctionPaymentRepository $payments,
        private readonly AuctionRefundRepository $refunds,
        private readonly AuctionSettlementRepository $settlements,
        private readonly AuctionRefundCompletion $completion,
    ) {}

    public function execute(AuctionDeposit $deposit, string $reason, ?int $adminId = null): RefundTransaction
    {
        return $this->transaction->run(function () use ($deposit, $reason, $adminId): RefundTransaction {
            $deposit = $this->deposits->lockForRefund($deposit->id);
            $payment = $this->payments->lockSucceededTransactionForObligation(FinancialObligationKey::forDeposit($deposit));

            if (! $payment) {
                throw AuctionException::domain('refund_exceeds_available');
            }

            $existingRefunds = $this->refunds->lockActiveOrSucceededForDeposit($deposit->id);
            $relatedSettlements = $this->settlements->lockForDepositRefund($deposit);
            $allocation = DepositRefundAllocation::calculateRefundableDepositAmount(
                $deposit,
                (int) $payment->amount_minor,
                $existingRefunds,
                $relatedSettlements
            );

            if ($allocation->isEmpty()) {
                if (DepositRefundAllocation::hasActiveAppliedSettlement($relatedSettlements)) {
                    throw AuctionException::domain('refund_exceeds_available');
                }

                throw AuctionException::domain('zero_refund_not_allowed');
            }

            $amount = $allocation->totalAmountMinor();
            $key = "deposit:{$deposit->id}:refund:held:{$allocation->heldAmountMinor}:applied:{$allocation->appliedAmountMinor}";

            $provider = (string) config('auction.refunds.provider', 'manual');

            $refund = $this->refunds->firstOrCreateRefund(
                ['provider' => $provider, 'idempotency_key' => $key],
                [
                    'auction_id' => $deposit->auction_id,
                    'deposit_id' => $deposit->id,
                    'payment_transaction_id' => $payment->id,
                    'obligation_type' => 'deposit',
                    'obligation_id' => $deposit->id,
                    'user_id' => $deposit->user_id,
                    'status' => RefundTransactionStatus::Pending,
                    'amount_minor' => $amount,
                    'held_refund_amount_minor' => $allocation->heldAmountMinor,
                    'applied_refund_amount_minor' => $allocation->appliedAmountMinor,
                    'currency_code' => $deposit->currency_code,
                    'reason' => $reason,
                ]
            );

            if ($refund->status === RefundTransactionStatus::Succeeded) {
                return $refund;
            }

            $deposit->forceFill([
                'status' => AuctionDepositStatus::RefundPending,
            ]);
            $this->deposits->save($deposit);

            if ($refund->wasRecentlyCreated) {
                $this->audit->log('auction.refund_created', $deposit->auction, $adminId, $adminId ? 'admin' : 'system', [
                    'deposit_public_id' => $deposit->public_id,
                    'refund_public_id' => $refund->public_id,
                    'amount_minor' => $amount,
                    'held_refund_amount_minor' => $allocation->heldAmountMinor,
                    'applied_refund_amount_minor' => $allocation->appliedAmountMinor,
                    'provider' => $provider,
                    'reason' => $reason,
                ]);
            }

            return $refund->refresh();
        });
    }

    public function confirmSucceeded(RefundTransaction $refund, string $providerRefundId, ?int $adminId = null): RefundTransaction
    {
        return $this->transaction->run(function () use ($refund, $providerRefundId, $adminId): RefundTransaction {
            return $this->completion->completeSucceeded(
                $this->refunds->lockForConfirmation($refund->id),
                $providerRefundId,
                actorId: $adminId,
                actorType: $adminId ? 'admin' : 'system',
            );
        });
    }
}
