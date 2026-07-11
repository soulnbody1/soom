<?php

declare(strict_types=1);

namespace App\Application\Auction\Actions;

use App\Application\Auction\Services\AuctionAudit;
use App\Application\Auction\Services\AuctionTransaction;
use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\RefundTransactionStatus;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\RefundTransaction;
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

            $refund = RefundTransaction::firstOrCreate(
                ['provider' => 'manual', 'idempotency_key' => $key],
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

            $refund->forceFill([
                'status' => RefundTransactionStatus::Succeeded,
                'processed_at' => Carbon::now(),
            ])->save();

            $deposit->forceFill([
                'status' => AuctionDepositStatus::Refunded,
                'refunded_amount_minor' => $deposit->refunded_amount_minor + $amount,
                'held_amount_minor' => 0,
                'released_at' => Carbon::now(),
            ])->save();

            $this->audit->log('auction.deposit_refunded', $deposit->auction, $adminId, $adminId ? 'admin' : 'system', [
                'deposit_public_id' => $deposit->public_id,
                'amount_minor' => $amount,
                'reason' => $reason,
            ]);

            return $refund->refresh();
        });
    }
}
