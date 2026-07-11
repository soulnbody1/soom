<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\PaymentTransactionStatus;
use App\Domain\Auction\Enums\RefundTransactionStatus;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\PaymentTransaction;
use App\Models\Auction\RefundTransaction;
use App\Repositories\Auction\AuctionRepository;
use App\Services\Auction\Support\AuctionStateMachine;
use App\Services\Auction\Support\AuctionTransaction;
use Illuminate\Support\Carbon;

final class CancelAuctionAction
{
    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionStateMachine $stateMachine,
        private readonly AuctionRepository $auctions,
    ) {}

    public function execute(Auction $auction, int $actorId, string $actorType, string $reason): Auction
    {
        return $this->transaction->run(function () use ($auction, $actorId, $actorType, $reason): Auction {
            $auction = $this->auctions->lockForStateChange($auction->id);

            $this->createRefundPlan($auction, $reason);

            $auction->settlement()
                ->whereNotIn('status', ['completed', 'cancelled'])
                ->lockForUpdate()
                ->get()
                ->each(function ($settlement): void {
                    $settlement->forceFill(['status' => 'cancelled'])->save();
                });

            return $this->stateMachine->transition(
                $auction,
                AuctionStatus::Cancelled,
                $actorId,
                $actorType,
                $reason
            );
        });
    }

    private function createRefundPlan(Auction $auction, string $reason): void
    {
        $provider = (string) config('auction.refunds.provider', 'manual');

        AuctionDeposit::where('auction_id', $auction->id)
            ->whereIn('status', [
                AuctionDepositStatus::Held->value,
                AuctionDepositStatus::AppliedToSettlement->value,
                AuctionDepositStatus::RefundPending->value,
            ])
            ->lockForUpdate()
            ->get()
            ->each(function (AuctionDeposit $deposit) use ($auction, $provider, $reason): void {
                $amount = max(
                    0,
                    (int) $deposit->held_amount_minor
                    + (int) $deposit->applied_amount_minor
                    - (int) $deposit->refunded_amount_minor
                );

                if ($amount <= 0) {
                    return;
                }

                RefundTransaction::firstOrCreate(
                    ['provider' => $provider, 'idempotency_key' => "auction:{$auction->id}:cancel:deposit:{$deposit->id}"],
                    [
                        'auction_id' => $auction->id,
                        'deposit_id' => $deposit->id,
                        'user_id' => $deposit->user_id,
                        'status' => RefundTransactionStatus::Pending,
                        'amount_minor' => $amount,
                        'currency_code' => $deposit->currency_code,
                        'reason' => "auction_cancelled: {$reason}",
                    ]
                );

                $deposit->forceFill(['status' => AuctionDepositStatus::RefundPending])->save();
            });

        PaymentTransaction::where('auction_id', $auction->id)
            ->where('status', PaymentTransactionStatus::Succeeded->value)
            ->lockForUpdate()
            ->get()
            ->each(function (PaymentTransaction $payment) use ($auction, $provider, $reason): void {
                RefundTransaction::firstOrCreate(
                    ['provider' => $provider, 'idempotency_key' => "auction:{$auction->id}:cancel:payment:{$payment->id}"],
                    [
                        'auction_id' => $auction->id,
                        'payment_transaction_id' => $payment->id,
                        'user_id' => $payment->user_id,
                        'status' => RefundTransactionStatus::Pending,
                        'amount_minor' => $payment->amount_minor,
                        'currency_code' => $payment->currency_code,
                        'reason' => "auction_cancelled: {$reason}",
                    ]
                );

                $payment->forceFill([
                    'status' => PaymentTransactionStatus::Reversed,
                    'processed_at' => $payment->processed_at ?? Carbon::now(),
                ])->save();
            });
    }
}
