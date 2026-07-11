<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\SettlementStatus;
use App\Models\Auction\Auction;
use App\Repositories\Auction\AuctionBidRepository;
use App\Repositories\Auction\AuctionDepositRepository;
use App\Repositories\Auction\AuctionRepository;
use App\Repositories\Auction\AuctionSettlementRepository;
use App\Services\Auction\Support\AuctionAudit;
use App\Services\Auction\Support\AuctionStateMachine;
use App\Services\Auction\Support\AuctionTransaction;
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
        private readonly AuctionSettlementRepository $settlements,
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

            $depositApplied = min($winnerDeposit?->held_amount_minor ?? 0, $winningBid->amount_minor);
            $platformFee = $this->platformFee($auction, $winningBid->amount_minor);
            $sellerNet = max(0, $winningBid->amount_minor - $platformFee);

            $settlement = $this->settlements->createSettlement([
                'auction_id' => $auction->id,
                'winning_bid_id' => $winningBid->id,
                'winner_id' => $winningBid->bidder_id,
                'status' => SettlementStatus::PaymentPending,
                'winning_amount_minor' => $winningBid->amount_minor,
                'deposit_applied_minor' => $depositApplied,
                'platform_fee_minor' => $platformFee,
                'seller_net_amount_minor' => $sellerNet,
                'amount_due_minor' => $winningBid->amount_minor - $depositApplied,
                'amount_paid_minor' => 0,
                'currency_code' => $auction->currency_code,
                'payment_due_at' => $now->addHours($auction->winner_payment_deadline_hours),
            ]);

            if ($winnerDeposit && $depositApplied > 0) {
                $winnerDeposit->forceFill([
                    'status' => AuctionDepositStatus::AppliedToSettlement,
                    'applied_amount_minor' => $depositApplied,
                    'held_amount_minor' => max(0, $winnerDeposit->held_amount_minor - $depositApplied),
                    'released_at' => $now,
                ]);
                $this->deposits->save($winnerDeposit);
            }

            $this->auctions->setWinningBid($auction, $winningBid->id);
            $this->deposits->markNonWinnerDepositsRefundPending($auction->id, $winningBid->bidder_id);
            $this->stateMachine->transition($auction, AuctionStatus::SettlementPending, null, 'system', 'winning bid selected');
            $this->stateMachine->transition($auction->refresh(), AuctionStatus::PaymentPending, null, 'system', 'settlement created');
            $this->audit->outbox('auction.finalized', $auction->refresh(), [
                'auction_public_id' => $auction->public_id,
                'settlement_public_id' => $settlement->public_id,
                'winner_id' => $winningBid->bidder_id,
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
}
