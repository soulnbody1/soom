<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\RefundTransactionStatus;
use App\Domain\Auction\Enums\SettlementStatus;
use App\DTO\Auction\CreateSettlementDTO;
use App\Models\Auction\Auction;
use App\Repositories\Auction\AuctionBidRepository;
use App\Repositories\Auction\AuctionDepositRepository;
use App\Repositories\Auction\AuctionPaymentRepository;
use App\Repositories\Auction\AuctionRefundRepository;
use App\Repositories\Auction\AuctionRepository;
use App\Repositories\Auction\AuctionSettlementRepository;
use App\Services\Auction\Support\AuctionAudit;
use App\Services\Auction\Support\AuctionStateMachine;
use App\Services\Auction\Support\AuctionTransaction;
use App\Services\Auction\Support\FinancialObligationKey;
use Illuminate\Support\Carbon;

final class FinalizeAuctionAction
{
    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionStateMachine $stateMachine,
        private readonly AuctionAudit $audit,
        private readonly AuctionRepository $auctions,
        private readonly AuctionBidRepository $bids,
        private readonly AuctionDepositRepository $deposits,
        private readonly AuctionPaymentRepository $payments,
        private readonly AuctionSettlementRepository $settlements,
        private readonly AuctionRefundRepository $refunds,
    ) {}

    public function execute(Auction $auction): Auction
    {
        return $this->transaction->run(function () use ($auction): Auction {
            $auction = $this->auctions->lockForFinalization($auction->id);
            $now = Carbon::now();

            if ($auction->status === AuctionStatus::Live) {
                if ($auction->ends_at && $now->lessThan($auction->ends_at)) {
                    return $auction;
                }

                $auction = $this->stateMachine->transition($auction, AuctionStatus::Ended, null, 'system', 'auction end time reached');
            }

            if (! in_array($auction->status, [AuctionStatus::Ended, AuctionStatus::SettlementPending, AuctionStatus::PaymentPending], true)) {
                return $auction;
            }

            if ($auction->settlement) {
                return $auction->load('settlement');
            }

            $winningBid = $this->bids->lockWinningBid($auction->id);

            if (! $winningBid || ($auction->reserve_amount_minor !== null && $winningBid->amount_minor < $auction->reserve_amount_minor)) {
                $this->deposits->markNonWinnerDepositsRefundPending($auction->id, null);

                return $this->stateMachine->transition($auction, AuctionStatus::Unsold, null, 'system', 'reserve not met or no bids');
            }

            $winnerDeposit = $this->deposits->lockWinnerDeposit($auction->id, $winningBid->bidder_id);

            $depositHeld = $winnerDeposit?->held_amount_minor ?? 0;
            $depositApplied = min($depositHeld, $winningBid->amount_minor);
            $depositExcess = max(0, $depositHeld - $depositApplied);
            $platformFee = $this->platformFee($auction, $winningBid->amount_minor);
            $sellerNet = max(0, $winningBid->amount_minor - $platformFee);
            $amountDue = $winningBid->amount_minor - $depositApplied;
            $handoverDueAt = $amountDue === 0
                ? $now->copy()->addHours($auction->handover_deadline_hours)
                : null;

            $settlementStatus = $amountDue > 0
                ? SettlementStatus::PaymentPending
                : SettlementStatus::Paid;

            $settlement = $this->settlements->createSettlement(new CreateSettlementDTO(
                auctionId: $auction->id,
                winningBidId: $winningBid->id,
                winnerId: $winningBid->bidder_id,
                status: $settlementStatus,
                winningAmountMinor: $winningBid->amount_minor,
                depositAppliedMinor: $depositApplied,
                platformFeeMinor: $platformFee,
                sellerNetAmountMinor: $sellerNet,
                amountDueMinor: $amountDue,
                amountPaidMinor: 0,
                remainingAmountMinor: $amountDue,
                currencyCode: $auction->currency_code,
                paymentDueAt: $amountDue > 0
                    ? $now->addHours($auction->winner_payment_deadline_hours)
                    : null,
                handoverDueAt: $handoverDueAt,
                paidAt: $amountDue === 0 ? $now : null,
            ));

            if ($winnerDeposit && $depositApplied > 0) {
                $winnerDeposit->forceFill([
                    'status' => AuctionDepositStatus::AppliedToSettlement,
                    'applied_amount_minor' => $depositApplied,
                    'held_amount_minor' => $depositExcess,
                    'released_at' => $depositExcess === 0 ? $now : null,
                ]);
                $this->deposits->save($winnerDeposit);
            }

            if ($depositExcess > 0 && $winnerDeposit) {
                $depositPayment = $this->payments->lockSucceededTransactionForObligation(FinancialObligationKey::forDeposit($winnerDeposit));

                if ($depositPayment) {
                    $winnerDeposit->forceFill([
                        'status' => AuctionDepositStatus::RefundPending,
                        'held_amount_minor' => $depositExcess,
                    ]);
                    $this->deposits->save($winnerDeposit);

                    $this->refunds->firstOrCreateRefund(
                        [
                            'provider' => (string) config('auction.refunds.provider', 'manual'),
                            'idempotency_key' => "auction:{$auction->id}:winner-deposit-excess:{$winnerDeposit->id}",
                        ],
                        [
                            'auction_id' => $auction->id,
                            'deposit_id' => $winnerDeposit->id,
                            'payment_transaction_id' => $depositPayment->id,
                            'obligation_type' => 'deposit',
                            'obligation_id' => $winnerDeposit->id,
                            'user_id' => $winnerDeposit->user_id,
                            'status' => RefundTransactionStatus::Pending,
                            'amount_minor' => $depositExcess,
                            'held_refund_amount_minor' => $depositExcess,
                            'applied_refund_amount_minor' => 0,
                            'currency_code' => $winnerDeposit->currency_code,
                            'reason' => 'winner deposit exceeds settlement amount',
                        ]
                    );
                }
            }

            $this->auctions->setWinningBid($auction, $winningBid->id);
            if ($this->shouldRefundNonWinnersImmediately($auction)) {
                $this->deposits->markNonWinnerDepositsRefundPending($auction->id, $winningBid->bidder_id);
            }
            $this->stateMachine->transition($auction, AuctionStatus::SettlementPending, null, 'system', 'winning bid selected');

            $nextStatus = $amountDue > 0
                ? AuctionStatus::PaymentPending
                : AuctionStatus::HandoverPending;
            $this->stateMachine->transition($auction->refresh(), $nextStatus, null, 'system', $amountDue > 0 ? 'settlement created' : 'fully paid by deposit');

            $this->audit->outbox('auction.finalized', $auction->refresh(), [
                'auction_public_id' => $auction->public_id,
                'settlement_public_id' => $settlement->public_id,
                'winner_id' => $winningBid->bidder_id,
                'remaining_amount' => $amountDue,
            ]);

            return $auction->refresh()->load('settlement');
        });
    }

    private function platformFee(Auction $auction, int $winningAmount): int
    {
        if ($auction->platform_fee_type === 'fixed') {
            return min($winningAmount, $auction->platform_fee_fixed_minor);
        }

        return intdiv($winningAmount * $auction->platform_fee_basis_points, 10_000);
    }

    private function shouldRefundNonWinnersImmediately(Auction $auction): bool
    {
        $policy = $auction->configurationVersion?->configuration['non_winner_deposit_policy']
            ?? config('auction.non_winner_deposit_policy', 'hold_all_eligible_bidders_until_winner_payment');

        return $policy === 'refund_all_non_winners_immediately';
    }
}
