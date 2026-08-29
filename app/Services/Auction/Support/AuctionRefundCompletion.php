<?php

declare(strict_types=1);

namespace App\Services\Auction\Support;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\PaymentTransactionStatus;
use App\Domain\Auction\Enums\RefundTransactionStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\PaymentTransaction;
use App\Models\Auction\RefundTransaction;
use App\Repositories\Auction\AuctionDepositRepository;
use App\Repositories\Auction\AuctionPaymentRepository;
use App\Repositories\Auction\AuctionRefundRepository;
use App\Repositories\Auction\AuctionSettlementRepository;
use Illuminate\Support\Carbon;

final class AuctionRefundCompletion
{
    public function __construct(
        private readonly AuctionAudit $audit,
        private readonly AuctionDepositRepository $deposits,
        private readonly AuctionPaymentRepository $payments,
        private readonly AuctionRefundRepository $refunds,
        private readonly AuctionSettlementRepository $settlements,
    ) {}

    public function completeSucceeded(
        RefundTransaction $refund,
        string $providerRefundId,
        ?string $expectedProcessingToken = null,
        ?array $providerResponse = null,
        ?int $manualConfirmedBy = null,
        ?string $manualConfirmationReason = null,
        ?int $actorId = null,
        string $actorType = 'system',
    ): RefundTransaction {
        if ($refund->status === RefundTransactionStatus::Succeeded) {
            return $refund;
        }

        if ($expectedProcessingToken !== null && $refund->processing_token !== $expectedProcessingToken) {
            throw AuctionException::domain('refund_processing_token_mismatch');
        }

        if ($this->refunds->providerRefundIdExists($refund->provider, $providerRefundId, $refund->id)) {
            throw AuctionException::domain('duplicate_provider_refund');
        }

        $deposit = $refund->deposit_id ? $this->deposits->lockForRefund($refund->deposit_id) : null;
        $payment = $this->lockPayment($refund, $deposit);

        if (! $deposit && ! $payment) {
            throw AuctionException::domain('refund_exceeds_available');
        }

        if ($refund->obligation_type === 'settlement' && $refund->obligation_id) {
            $this->settlements->lockById((int) $refund->obligation_id);
        }

        $amount = (int) $refund->amount_minor;

        if ($amount <= 0) {
            throw AuctionException::domain('refund_exceeds_available');
        }

        if ($payment) {
            if ($payment->status !== PaymentTransactionStatus::Succeeded) {
                throw AuctionException::domain('refund_exceeds_available');
            }

            $alreadyRefunded = $this->refunds->succeededAmountForPayment($payment->id);

            if ($alreadyRefunded + $amount > (int) $payment->amount_minor) {
                throw AuctionException::domain('refund_exceeds_available');
            }
        }

        $allocation = null;

        if ($deposit) {
            $allocation = DepositRefundAllocation::fromRefund($refund);

            if ($allocation->totalAmountMinor() !== $amount) {
                throw AuctionException::domain('refund_exceeds_available');
            }

            $available = DepositRefundAllocation::calculateRefundableDepositAmount(
                $deposit,
                (int) ($payment?->amount_minor ?? 0),
                $this->refunds->lockActiveOrSucceededForDeposit($deposit->id),
                $this->settlements->lockForDepositRefund($deposit),
                $refund->id
            );

            if (
                $allocation->heldAmountMinor > $available->heldAmountMinor
                || $allocation->appliedAmountMinor > $available->appliedAmountMinor
            ) {
                throw AuctionException::domain('refund_exceeds_available');
            }
        }

        $now = Carbon::now();
        $refund->forceFill([
            'payment_transaction_id' => $refund->payment_transaction_id ?? $payment?->id,
            'status' => RefundTransactionStatus::Succeeded,
            'provider_refund_id' => $providerRefundId,
            'provider_response' => $providerResponse ?? $refund->provider_response,
            'processing_token' => null,
            'lease_expires_at' => null,
            'next_retry_at' => null,
            'last_error' => null,
            'failure_reason' => null,
            'manual_confirmed_by' => $manualConfirmedBy ?? $refund->manual_confirmed_by,
            'manual_confirmed_at' => $manualConfirmedBy ? $now : $refund->manual_confirmed_at,
            'manual_confirmation_reason' => $manualConfirmationReason ?? $refund->manual_confirmation_reason,
            'processed_at' => $now,
            'succeeded_at' => $now,
        ]);
        $this->refunds->save($refund);

        if ($deposit && $allocation) {
            $this->applyDepositAllocation($deposit, $allocation);
        }

        $this->audit->log('auction.refund_succeeded', $refund->auction, $actorId, $actorType, [
            'refund_public_id' => $refund->public_id,
            'provider' => $refund->provider,
            'provider_refund_id' => $providerRefundId,
            'amount_minor' => $refund->amount_minor,
            'manual_confirmed_by' => $manualConfirmedBy,
        ]);
        $this->audit->outbox('auction.refund_succeeded', $refund->auction, [
            'refund_transaction_id' => $refund->id,
            'user_id' => $refund->user_id,
            'status' => RefundTransactionStatus::Succeeded->value,
        ]);

        return $refund->refresh();
    }

    private function lockPayment(RefundTransaction $refund, ?AuctionDeposit $deposit): ?PaymentTransaction
    {
        if ($refund->payment_transaction_id) {
            return $this->payments->lockTransactionForRefund($refund->payment_transaction_id);
        }

        return $deposit
            ? $this->payments->lockSucceededTransactionForObligation(FinancialObligationKey::forDeposit($deposit))
            : null;
    }

    private function applyDepositAllocation(AuctionDeposit $deposit, DepositRefundAllocation $allocation): void
    {
        $amount = $allocation->totalAmountMinor();
        $heldRemaining = (int) $deposit->held_amount_minor - $allocation->heldAmountMinor;
        $appliedRemaining = (int) $deposit->applied_amount_minor - $allocation->appliedAmountMinor;

        $deposit->forceFill([
            'status' => ($heldRemaining + $appliedRemaining) === 0
                ? AuctionDepositStatus::Refunded
                : AuctionDepositStatus::RefundPending,
            'refunded_amount_minor' => $deposit->refunded_amount_minor + $amount,
            'held_amount_minor' => $heldRemaining,
            'applied_amount_minor' => $appliedRemaining,
            'released_at' => ($heldRemaining + $appliedRemaining) === 0 ? Carbon::now() : $deposit->released_at,
        ]);
        $this->deposits->save($deposit);
    }
}
