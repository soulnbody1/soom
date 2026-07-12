<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Enums\RefundTransactionStatus;
use App\Models\Auction\Auction;
use App\Models\Auction\PaymentTransaction;
use App\Repositories\Auction\AuctionDepositRepository;
use App\Repositories\Auction\AuctionPaymentRepository;
use App\Repositories\Auction\AuctionRefundRepository;
use App\Repositories\Auction\AuctionRepository;
use App\Repositories\Auction\AuctionSettlementRepository;
use App\Services\Auction\Support\AuctionStateMachine;
use App\Services\Auction\Support\AuctionTransaction;

final class CancelAuctionAction
{
    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionStateMachine $stateMachine,
        private readonly AuctionRepository $auctions,
        private readonly AuctionDepositRepository $deposits,
        private readonly AuctionPaymentRepository $payments,
        private readonly AuctionRefundRepository $refunds,
        private readonly AuctionSettlementRepository $settlements,
    ) {}

    public function execute(Auction $auction, int $actorId, string $actorType, string $reason): Auction
    {
        return $this->transaction->run(function () use ($auction, $actorId, $actorType, $reason): Auction {
            $auction = $this->auctions->lockForStateChange($auction->id);

            $this->createRefundPlan($auction, $reason);

            $this->settlements->lockCancellableForAuction($auction->id)
                ->each(function ($settlement): void {
                    $settlement->forceFill(['status' => 'cancelled']);
                    $this->settlements->save($settlement);
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

        $this->deposits->lockRefundableForCancellation($auction->id);

        $this->payments->lockSucceededTransactionsForAuction($auction->id)
            ->each(function (PaymentTransaction $payment) use ($auction, $provider, $reason): void {
                $existingRefunds = $this->refunds->lockActiveOrSucceededForPayment($payment->id);
                if ($existingRefunds->isNotEmpty()) {
                    return;
                }

                $submission = $payment->submission;
                $deposit = $submission?->deposit;
                $settlement = $submission?->settlement;

                $obligationType = match ($payment->purpose) {
                    PaymentPurpose::SellerDeposit, PaymentPurpose::BidderDeposit => 'deposit',
                    PaymentPurpose::WinnerSettlement => 'settlement',
                };
                $obligationId = $deposit?->id ?? $settlement?->id;

                if ($obligationId === null) {
                    return;
                }

                $this->refunds->firstOrCreateRefund(
                    ['provider' => $provider, 'idempotency_key' => "auction:{$auction->id}:cancel:payment:{$payment->id}"],
                    [
                        'auction_id' => $auction->id,
                        'deposit_id' => $deposit?->id,
                        'payment_transaction_id' => $payment->id,
                        'obligation_type' => $obligationType,
                        'obligation_id' => $obligationId,
                        'user_id' => $payment->user_id,
                        'status' => RefundTransactionStatus::Pending,
                        'amount_minor' => $payment->amount_minor,
                        'currency_code' => $payment->currency_code,
                        'reason' => "auction_cancelled: {$reason}",
                    ]
                );

                if ($deposit && $deposit->status !== AuctionDepositStatus::Refunded) {
                    $deposit->forceFill(['status' => AuctionDepositStatus::RefundPending]);
                    $this->deposits->save($deposit);
                }
            });
    }
}
