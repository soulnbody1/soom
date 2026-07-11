<?php

declare(strict_types=1);

namespace App\Application\Auction\Actions;

use App\Application\Auction\Services\AuctionAudit;
use App\Application\Auction\Services\AuctionStateMachine;
use App\Application\Auction\Services\AuctionTransaction;
use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\SettlementStatus;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionBid;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\AuctionSettlement;
use Illuminate\Support\Carbon;

final class FinalizeAuctionAction
{
    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionStateMachine $stateMachine,
        private readonly AuctionAudit $audit
    ) {}

    public function execute(Auction $auction): Auction
    {
        return $this->transaction->run(function () use ($auction): Auction {
            $auction = Auction::whereKey($auction->id)->lockForUpdate()->firstOrFail();
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

            $winningBid = AuctionBid::where('auction_id', $auction->id)
                ->orderByDesc('amount_minor')
                ->orderBy('sequence_number')
                ->lockForUpdate()
                ->first();

            if (! $winningBid || ($auction->reserve_amount_minor !== null && $winningBid->amount_minor < $auction->reserve_amount_minor)) {
                $this->markRefundsPending($auction, null);

                return $this->stateMachine->transition($auction, AuctionStatus::Unsold, null, 'system', 'reserve not met or no bids');
            }

            $winnerDeposit = AuctionDeposit::where('auction_id', $auction->id)
                ->where('user_id', $winningBid->bidder_id)
                ->where('type', 'bidder')
                ->lockForUpdate()
                ->first();

            $depositApplied = min($winnerDeposit?->held_amount_minor ?? 0, $winningBid->amount_minor);
            $platformFee = $this->platformFee($auction, $winningBid->amount_minor);
            $sellerNet = max(0, $winningBid->amount_minor - $platformFee);

            $settlement = AuctionSettlement::create([
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
                ])->save();
            }

            $auction->forceFill(['winning_bid_id' => $winningBid->id])->save();
            $this->markRefundsPending($auction, $winningBid->bidder_id);
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

    private function markRefundsPending(Auction $auction, ?int $exceptUserId): void
    {
        AuctionDeposit::where('auction_id', $auction->id)
            ->where('type', 'bidder')
            ->where('status', AuctionDepositStatus::Held->value)
            ->when($exceptUserId, fn ($query) => $query->where('user_id', '!=', $exceptUserId))
            ->update(['status' => AuctionDepositStatus::RefundPending->value]);
    }
}
