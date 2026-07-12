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
use App\Services\Auction\Support\DepositRefundAllocation;

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
        private readonly PlanNonWinnerDepositRefundsAction $nonWinnerDeposits,
        private readonly ResolveSellerDepositDispositionAction $sellerDepositDisposition,
    ) {}

    public function execute(Auction $auction, int $actorId, string $actorType, string $reason): Auction
    {
        return $this->transaction->run(function () use ($auction, $actorId, $actorType, $reason): Auction {
            $auction = $this->auctions->lockForStateChange($auction->id);

            $this->settlements->lockCancellableForAuction($auction->id)
                ->each(function ($settlement): void {
                    $settlement->forceFill(['status' => 'cancelled']);
                    $this->settlements->save($settlement);
                });

            $this->sellerDepositDisposition->execute($auction, 'cancellation', $actorId, $actorType, $reason, [
                'auction_status_before' => $auction->status,
            ]);
            $this->createRefundPlan($auction, $reason);

            $auction = $this->stateMachine->transition(
                $auction,
                AuctionStatus::Cancelled,
                $actorId,
                $actorType,
                $reason
            );
            $this->nonWinnerDeposits->execute($auction, 'cancelled', $actorId, $actorType);

            return $auction->refresh();
        });
    }

    private function createRefundPlan(Auction $auction, string $reason): void
    {
        $provider = (string) config('auction.refunds.provider', 'manual');

        $this->deposits->lockRefundableForCancellation($auction->id);

        $this->payments->lockSucceededTransactionsForAuction($auction->id)
            ->each(function (PaymentTransaction $payment) use ($auction, $provider, $reason): void {
                $submission = $payment->submission;
                $deposit = $submission?->deposit;
                $settlement = $submission?->settlement;

                if ($payment->purpose === PaymentPurpose::SellerDeposit) {
                    return;
                }

                $obligationType = match ($payment->purpose) {
                    PaymentPurpose::SellerDeposit, PaymentPurpose::BidderDeposit => 'deposit',
                    PaymentPurpose::WinnerSettlement => 'settlement',
                };
                $obligationId = $deposit?->id ?? $settlement?->id;
                $amount = (int) $payment->amount_minor;
                $heldRefundAmount = 0;
                $appliedRefundAmount = 0;

                if ($obligationId === null) {
                    return;
                }

                if ($deposit) {
                    $depositRefunds = $this->refunds->lockActiveOrSucceededForDeposit($deposit->id);
                    $allocation = DepositRefundAllocation::calculateRefundableDepositAmount(
                        $deposit,
                        (int) $payment->amount_minor,
                        $depositRefunds,
                        $this->settlements->lockForDepositRefund($deposit)
                    );

                    if ($allocation->isEmpty()) {
                        return;
                    }

                    $amount = $allocation->totalAmountMinor();
                    $heldRefundAmount = $allocation->heldAmountMinor;
                    $appliedRefundAmount = $allocation->appliedAmountMinor;
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
                        'amount_minor' => $amount,
                        'held_refund_amount_minor' => $heldRefundAmount,
                        'applied_refund_amount_minor' => $appliedRefundAmount,
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
