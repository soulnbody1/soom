<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\RefundTransactionStatus;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\RefundTransaction;
use App\Services\Auction\Support\AuctionAudit;
use App\Services\Auction\Support\AuctionTransaction;
use Illuminate\Support\Carbon;

final class RefundAuctionDepositAction
{
    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionAudit $audit
    ) {}

    public function execute(AuctionDeposit $deposit, string $reason, ?int $adminId = null): RefundTransaction
    {
        return $this->transaction->run(function () use ($deposit, $reason, $adminId): RefundTransaction {
            $deposit = AuctionDeposit::whereKey($deposit->id)->lockForUpdate()->firstOrFail();

            $amount = $deposit->held_amount_minor;
            $key = "deposit:{$deposit->id}:refund:{$amount}";

            $provider = (string) config('auction.refunds.provider', 'manual');

            $refund = RefundTransaction::firstOrCreate(
                ['provider' => $provider, 'idempotency_key' => $key],
                [
                    'auction_id' => $deposit->auction_id,
                    'deposit_id' => $deposit->id,
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
            ])->save();

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
            $refund = RefundTransaction::whereKey($refund->id)->lockForUpdate()->firstOrFail();
            $deposit = AuctionDeposit::whereKey($refund->deposit_id)->lockForUpdate()->firstOrFail();

            $this->markSucceeded($refund, $deposit, $refund->amount_minor, $providerRefundId);

            $this->audit->log('auction.deposit_refunded', $deposit->auction, $adminId, $adminId ? 'admin' : 'system', [
                'deposit_public_id' => $deposit->public_id,
                'refund_public_id' => $refund->public_id,
                'provider_refund_id' => $providerRefundId,
            ]);

            return $refund->refresh();
        });
    }

    private function markSucceeded(
        RefundTransaction $refund,
        AuctionDeposit $deposit,
        int $amount,
        ?string $providerRefundId = null
    ): void {
        $refund->forceFill([
            'status' => RefundTransactionStatus::Succeeded,
            'provider_refund_id' => $providerRefundId ?? $refund->provider_refund_id,
            'processed_at' => Carbon::now(),
        ])->save();

        $deposit->forceFill([
            'status' => AuctionDepositStatus::Refunded,
            'refunded_amount_minor' => $deposit->refunded_amount_minor + $amount,
            'held_amount_minor' => 0,
            'released_at' => Carbon::now(),
        ])->save();
    }
}
