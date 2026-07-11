<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\AuctionParticipantStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\SettlementStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionBid;
use App\Models\Auction\AuctionWinnerReassignment;
use App\Repositories\Auction\AuctionBidRepository;
use App\Repositories\Auction\AuctionDepositRepository;
use App\Repositories\Auction\AuctionRepository;
use App\Repositories\Auction\AuctionSettlementRepository;
use App\Services\Auction\Support\AuctionAudit;
use App\Services\Auction\Support\AuctionStateMachine;
use App\Services\Auction\Support\AuctionTransaction;
use Illuminate\Support\Carbon;

final class MarkWinnerDefaultedAction
{
    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionStateMachine $stateMachine,
        private readonly AuctionAudit $audit,
        private readonly AuctionRepository $auctions,
        private readonly AuctionSettlementRepository $settlements,
        private readonly AuctionBidRepository $bids,
        private readonly AuctionDepositRepository $deposits,
    ) {}

    public function execute(Auction $auction, int $adminId, string $reason, bool $reassignToNext = false): Auction
    {
        if (trim($reason) === '') {
            throw new AuctionException(__('auction.errors.default_reason_required'));
        }

        return $this->transaction->run(function () use ($auction, $adminId, $reason, $reassignToNext): Auction {
            $auction = $this->auctions->lockForStateChange($auction->id);
            $settlement = $this->settlements->lockSettlement($auction->id);
            $defaultedUserId = $settlement->winner_id;

            // Mark current settlement as Defaulted (preserve history, do NOT reuse)
            $settlement->forceFill([
                'status' => SettlementStatus::Defaulted,
            ]);
            $this->settlements->save($settlement);

            // Forfeit defaulted winner's deposit
            $winnerDeposit = $this->deposits->lockDepositForForfeiture($auction->id, $defaultedUserId);
            if ($winnerDeposit && $winnerDeposit->held_amount_minor > 0) {
                $winnerDeposit->forceFill([
                    'status' => AuctionDepositStatus::Forfeited,
                    'forfeited_amount_minor' => $winnerDeposit->held_amount_minor,
                    'held_amount_minor' => 0,
                    'released_at' => Carbon::now(),
                ]);
                $this->deposits->save($winnerDeposit);
            }

            $this->audit->log('auction.winner_defaulted', $auction, $adminId, 'admin', [
                'defaulted_user_id' => $defaultedUserId,
                'reason' => $reason,
            ]);
            $this->audit->outbox('auction.winner_defaulted', $auction, [
                'auction_public_id' => $auction->public_id,
                'defaulted_user_id' => $defaultedUserId,
                'reason' => $reason,
            ]);

            if ($reassignToNext) {
                $alternativeBid = $this->findEligibleAlternativeBid($auction, $defaultedUserId);

                if ($alternativeBid) {
                    return $this->assignAlternativeWinner($auction, $settlement, $alternativeBid, $adminId, $reason);
                }
            }

            // No eligible alternative → transition to Defaulted or Unsold
            return $this->stateMachine->transition($auction, AuctionStatus::Defaulted, $adminId, 'admin', $reason);
        });
    }

    /**
     * Find the next eligible bid, excluding ALL bids from the defaulted bidder.
     * Also validates the alternative bidder is still eligible.
     */
    private function findEligibleAlternativeBid(Auction $auction, int $defaultedUserId): ?AuctionBid
    {
        // Get all bids ordered by amount desc, sequence asc, EXCLUDING defaulted user entirely
        $candidates = AuctionBid::where('auction_id', $auction->id)
            ->where('bidder_id', '!=', $defaultedUserId)
            ->orderByDesc('amount_minor')
            ->orderBy('sequence_number')
            ->lockForUpdate()
            ->get();

        foreach ($candidates as $candidateBid) {
            // Check participant is still qualified
            $participant = $candidateBid->participant;
            if (! $participant || $participant->status !== AuctionParticipantStatus::Qualified) {
                continue;
            }

            // Check deposit is still held (not refunded)
            $deposit = $this->deposits->lockWinnerDeposit($auction->id, $candidateBid->bidder_id);
            if (! $deposit || $deposit->status !== AuctionDepositStatus::Held || $deposit->held_amount_minor <= 0) {
                continue;
            }

            // Check terms acceptance
            if ($participant->terms_accepted_at === null) {
                continue;
            }

            return $candidateBid;
        }

        return null;
    }

    /**
     * Create a NEW settlement for the alternative winner.
     * Never reuses the old defaulted settlement.
     */
    private function assignAlternativeWinner(
        Auction $auction,
        $defaultedSettlement,
        AuctionBid $newBid,
        int $adminId,
        string $reason
    ): Auction {
        $now = Carbon::now();

        // Calculate new settlement financials
        $winningAmount = $newBid->amount_minor;
        $deposit = $this->deposits->lockWinnerDeposit($auction->id, $newBid->bidder_id);
        $depositApplied = $deposit ? min($deposit->held_amount_minor, $winningAmount) : 0;
        $platformFee = $this->calculatePlatformFee($auction, $winningAmount);
        $sellerNet = $winningAmount - $platformFee;
        $amountDue = max(0, $winningAmount - $depositApplied);

        // Record reassignment
        AuctionWinnerReassignment::create([
            'auction_id' => $auction->id,
            'from_bid_id' => $defaultedSettlement->winning_bid_id,
            'to_bid_id' => $newBid->id,
            'from_user_id' => $defaultedSettlement->winner_id,
            'to_user_id' => $newBid->bidder_id,
            'created_by' => $adminId,
            'reason' => $reason,
            'metadata' => [
                'previous_amount_minor' => $defaultedSettlement->winning_amount_minor,
                'new_amount_minor' => $winningAmount,
            ],
            'created_at' => $now,
        ]);

        // Create NEW settlement (never reuse old one)
        $newSettlement = $this->settlements->createSettlement([
            'auction_id' => $auction->id,
            'winning_bid_id' => $newBid->id,
            'winner_id' => $newBid->bidder_id,
            'status' => $amountDue > 0 ? SettlementStatus::PaymentPending : SettlementStatus::Paid,
            'winning_amount_minor' => $winningAmount,
            'deposit_applied_minor' => $depositApplied,
            'platform_fee_minor' => $platformFee,
            'seller_net_amount_minor' => $sellerNet,
            'amount_due_minor' => $amountDue,
            'amount_paid_minor' => $depositApplied,
            'currency_code' => $auction->currency_code,
            'payment_due_at' => $amountDue > 0
                ? $now->copy()->addHours($auction->winner_payment_deadline_hours)
                : null,
        ]);

        // Apply deposit if exists
        if ($deposit && $depositApplied > 0) {
            $deposit->forceFill([
                'status' => AuctionDepositStatus::Applied,
                'applied_amount_minor' => $depositApplied,
                'held_amount_minor' => $deposit->held_amount_minor - $depositApplied,
            ]);
            $this->deposits->save($deposit);
        }

        $auction->forceFill(['winning_bid_id' => $newBid->id]);
        $this->auctions->save($auction);

        $this->audit->log('auction.alternative_winner_selected', $auction, $adminId, 'admin', [
            'new_winner_id' => $newBid->bidder_id,
            'new_bid_public_id' => $newBid->public_id,
            'new_settlement_public_id' => $newSettlement->public_id,
        ]);
        $this->audit->outbox('auction.alternative_winner_selected', $auction, [
            'auction_public_id' => $auction->public_id,
            'new_winner_id' => $newBid->bidder_id,
            'new_amount_minor' => $winningAmount,
        ]);

        $targetStatus = $amountDue > 0 ? AuctionStatus::PaymentPending : AuctionStatus::HandoverPending;

        return $this->stateMachine->transition($auction, $targetStatus, $adminId, 'admin', $reason);
    }

    /**
     * Calculate platform fee using auction's snapshotted configuration.
     */
    private function calculatePlatformFee(Auction $auction, int $winningAmountMinor): int
    {
        if ($auction->platform_fee_type === 'fixed') {
            return $auction->platform_fee_fixed_minor;
        }

        // Percentage: basis_points / 10000 * amount, integer arithmetic
        return (int) (($winningAmountMinor * $auction->platform_fee_basis_points) / 10000);
    }
}
