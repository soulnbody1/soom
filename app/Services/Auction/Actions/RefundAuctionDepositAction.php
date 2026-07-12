<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

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
use App\Services\Auction\Support\AuctionAudit;
use App\Services\Auction\Support\AuctionTransaction;
use Illuminate\Support\Carbon;

final class RefundAuctionDepositAction
{
    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionAudit $audit,
        private readonly AuctionDepositRepository $deposits,
        private readonly AuctionPaymentRepository $payments,
        private readonly AuctionRefundRepository $refunds,
    ) {}

    public function execute(AuctionDeposit $deposit, string $reason, ?int $adminId = null): RefundTransaction
    {
        return $this->transaction->run(function () use ($deposit, $reason, $adminId): RefundTransaction {
            $deposit = $this->deposits->lockForRefund($deposit->id);

            $amount = $deposit->held_amount_minor;

            if ($amount <= 0) {
                throw new AuctionException(__('auction.errors.zero_refund_not_allowed'));
            }

            if ($amount > $deposit->held_amount_minor) {
                throw new AuctionException(__('auction.errors.refund_exceeds_available'));
            }

            $key = "deposit:{$deposit->id}:refund:{$amount}";

            $provider = (string) config('auction.refunds.provider', 'manual');

            $refund = $this->refunds->firstOrCreateRefund(
                ['provider' => $provider, 'idempotency_key' => $key],
                [
                    'auction_id' => $deposit->auction_id,
                    'deposit_id' => $deposit->id,
                    'obligation_type' => 'deposit',
                    'obligation_id' => $deposit->id,
                    'user_id' => $deposit->user_id,
                    'status' => RefundTransactionStatus::Pending,
                    'amount_minor' => $amount,
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

            if (config('auction.refunds.auto_succeed_manual_refunds') === true && $provider === 'manual') {
                $this->markSucceeded($refund, $deposit, $amount);
            }

            $this->audit->log('auction.deposit_refund_requested', $deposit->auction, $adminId, $adminId ? 'admin' : 'system', [
                'deposit_public_id' => $deposit->public_id,
                'refund_public_id' => $refund->public_id,
                'amount_minor' => $amount,
                'reason' => $reason,
            ]);

            return $refund->refresh();
        });
    }

    public function confirmSucceeded(RefundTransaction $refund, string $providerRefundId, ?int $adminId = null): RefundTransaction
    {
        return $this->transaction->run(function () use ($refund, $providerRefundId, $adminId): RefundTransaction {
            $refund = $this->refunds->lockForConfirmation($refund->id);
            $deposit = $refund->deposit_id ? $this->deposits->lockForRefund($refund->deposit_id) : null;
            $payment = $refund->payment_transaction_id
                ? $this->payments->lockTransactionForRefund($refund->payment_transaction_id)
                : null;

            if ($refund->status === RefundTransactionStatus::Succeeded) {
                return $refund;
            }

            if ($this->refunds->providerRefundIdExists($refund->provider, $providerRefundId, $refund->id)) {
                throw new AuctionException(__('auction.errors.duplicate_provider_refund'));
            }

            $this->markSucceeded($refund, $deposit, $refund->amount_minor, $providerRefundId, $payment);

            $this->audit->log('auction.deposit_refunded', $deposit?->auction ?? $refund->auction, $adminId, $adminId ? 'admin' : 'system', [
                'deposit_public_id' => $deposit?->public_id,
                'refund_public_id' => $refund->public_id,
                'provider_refund_id' => $providerRefundId,
            ]);

            return $refund->refresh();
        });
    }

    private function markSucceeded(
        RefundTransaction $refund,
        ?AuctionDeposit $deposit,
        int $amount,
        ?string $providerRefundId = null,
        ?PaymentTransaction $payment = null
    ): void {
        if ($refund->status === RefundTransactionStatus::Succeeded) {
            return;
        }

        if ($amount <= 0) {
            throw new AuctionException(__('auction.errors.refund_exceeds_available'));
        }

        if ($payment && $amount > $payment->amount_minor) {
            throw new AuctionException(__('auction.errors.refund_exceeds_available'));
        }

        if (! $payment && (! $deposit || $amount > $deposit->held_amount_minor)) {
            throw new AuctionException(__('auction.errors.refund_exceeds_available'));
        }

        $refund->forceFill([
            'status' => RefundTransactionStatus::Succeeded,
            'provider_refund_id' => $providerRefundId ?? $refund->provider_refund_id,
            'processed_at' => Carbon::now(),
        ]);
        $this->refunds->save($refund);

        if ($payment && $payment->status !== PaymentTransactionStatus::Reversed) {
            $payment->forceFill(['status' => PaymentTransactionStatus::Reversed]);
            $this->payments->saveTransaction($payment);
        }

        if ($deposit && $amount <= $deposit->held_amount_minor) {
            $deposit->forceFill([
                'status' => AuctionDepositStatus::Refunded,
                'refunded_amount_minor' => $deposit->refunded_amount_minor + $amount,
                'held_amount_minor' => 0,
                'released_at' => Carbon::now(),
            ]);
            $this->deposits->save($deposit);
        }
    }
}
