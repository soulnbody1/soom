<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\AuctionParticipantStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\PaymentSubmissionStatus;
use App\Domain\Auction\Enums\SettlementStatus;
use App\Domain\Auction\Enums\WinnerDefaultDepositDisposition;
use App\Domain\Auction\Exceptions\AuctionException;
use App\DTO\Auction\CreateSettlementDTO;
use App\DTO\Auction\WinnerDefaultDepositDispositionDTO;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionBid;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\AuctionWinnerReassignment;
use App\Models\User;
use App\Repositories\Auction\AuctionBidRepository;
use App\Repositories\Auction\AuctionDepositRepository;
use App\Repositories\Auction\AuctionPaymentRepository;
use App\Repositories\Auction\AuctionRepository;
use App\Repositories\Auction\AuctionSettlementRepository;
use App\Repositories\Auction\AuctionTermsRepository;
use App\Repositories\Auction\AuctionWinnerReassignmentRepository;
use App\Services\Auction\Support\AuctionAudit;
use App\Services\Auction\Support\AuctionConfigurationSnapshotReader;
use App\Services\Auction\Support\AuctionStateMachine;
use App\Services\Auction\Support\AuctionTransaction;
use App\Services\Auction\Support\FinancialObligationKey;
use App\Services\Auction\Support\WinnerDefaultDepositDispositionResolver;
use Illuminate\Support\Carbon;

final class MarkWinnerDefaultedAction
{
    public const OVERRIDE_DEADLINE_PERMISSION = 'auction.winners.override_payment_deadline';

    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionStateMachine $stateMachine,
        private readonly AuctionAudit $audit,
        private readonly AuctionRepository $auctions,
        private readonly AuctionSettlementRepository $settlements,
        private readonly AuctionBidRepository $bids,
        private readonly AuctionDepositRepository $deposits,
        private readonly AuctionPaymentRepository $payments,
        private readonly AuctionTermsRepository $terms,
        private readonly AuctionWinnerReassignmentRepository $winnerReassignments,
        private readonly PlanNonWinnerDepositRefundsAction $nonWinnerDeposits,
        private readonly ResolveSellerDepositDispositionAction $sellerDepositDisposition,
        private readonly RefundAuctionDepositAction $refundDeposit,
        private readonly WinnerDefaultDepositDispositionResolver $depositDispositionResolver,
        private readonly AuctionConfigurationSnapshotReader $snapshotReader,
    ) {}

    public function execute(
        Auction $auction,
        int $adminId,
        string $reason,
        bool $reassignToNext = false,
        bool $overrideDeadline = false,
        string $overrideReason = ''
    ): Auction {
        if (trim($reason) === '') {
            throw new AuctionException(__('auction.errors.default_reason_required'));
        }

        return $this->transaction->run(function () use ($auction, $adminId, $reason, $reassignToNext, $overrideDeadline, $overrideReason): Auction {
            $auction = $this->auctions->lockForStateChange($auction->id);
            $snapshot = $this->snapshotReader->forAuction($auction);
            $settlement = $this->settlements->lockCurrentSettlementForPayment($auction->id);

            if (! $settlement && in_array($auction->status, [AuctionStatus::Defaulted, AuctionStatus::Unsold], true)) {
                return $auction->refresh();
            }

            if ($auction->status !== AuctionStatus::PaymentPending) {
                throw new AuctionException(__('auction.errors.winner_default_state_not_allowed'));
            }

            if (! $settlement) {
                throw new AuctionException(__('auction.errors.current_settlement_missing'));
            }

            $this->assertDefaultableSettlement($auction, $settlement);
            $originalDeadline = $this->assertDeadline($settlement, $overrideDeadline, $overrideReason, $adminId);

            if ($this->payments->lockSucceededTransactionForObligation(FinancialObligationKey::forSettlement($settlement))) {
                throw new AuctionException(__('auction.errors.payment_obligation_already_paid'));
            }

            $defaultedUserId = (int) $settlement->winner_id;
            $currentWinningBid = AuctionBid::whereKey($settlement->winning_bid_id)->lockForUpdate()->firstOrFail();
            $winnerDeposit = $this->deposits->lockDepositForForfeiture($auction->id, $defaultedUserId);
            $disposition = $this->depositDispositionResolver->resolve($auction, $winnerDeposit, $reason);

            $this->settlements->closeAsHistorical(
                $settlement,
                $reason,
                $originalDeadline ? $adminId : null,
                $originalDeadline ? trim($overrideReason) : null
            );

            $this->supersedePendingSettlementSubmissions($settlement->id, $adminId);
            $this->resolveDefaultedWinnerDeposit($auction, $winnerDeposit, $disposition, $adminId);

            $this->audit->log('auction.winner_defaulted', $auction, $adminId, 'admin', [
                'defaulted_user_id' => $defaultedUserId,
                'settlement_public_id' => $settlement->public_id,
                'winning_bid_public_id' => $currentWinningBid->public_id,
                'reason' => $reason,
                'deadline_overridden' => $originalDeadline !== null,
                'original_payment_due_at' => $originalDeadline?->toIso8601String(),
                'deposit_disposition' => $disposition->metadata(),
            ]);
            $this->audit->outbox('auction.winner_defaulted', $auction, [
                'auction_public_id' => $auction->public_id,
                'settlement_public_id' => $settlement->public_id,
                'defaulted_user_id' => $defaultedUserId,
                'reason' => $reason,
                'deadline_overridden' => $originalDeadline !== null,
            ]);

            if ($reassignToNext) {
                $alternativeBid = $snapshot->alternative_winner_enabled
                    ? $this->findEligibleAlternativeBid($auction, $defaultedUserId)
                    : null;

                if ($alternativeBid) {
                    $auction = $this->assignAlternativeWinner($auction, $settlement, $alternativeBid, $adminId, $reason);
                    $this->nonWinnerDeposits->execute($auction, 'alternative_selected', $adminId, 'admin', [$defaultedUserId]);
                    $this->sellerDepositDisposition->execute($auction, 'winner_default', $adminId, 'admin', $reason);

                    return $auction->refresh();
                }
            }

            $auction = $this->stateMachine->transition($auction, AuctionStatus::Defaulted, $adminId, 'admin', $reason);
            $auction->forceFill(['winning_bid_id' => null]);
            $this->auctions->save($auction);
            $auction = $this->stateMachine->transition($auction->refresh(), AuctionStatus::Unsold, $adminId, 'admin', $reason);

            $this->audit->outbox('auction.no_alternative_winner', $auction, [
                'auction_public_id' => $auction->public_id,
                'defaulted_user_id' => $defaultedUserId,
                'settlement_public_id' => $settlement->public_id,
            ]);

            $this->nonWinnerDeposits->execute($auction, 'no_alternative', $adminId, 'admin', [$defaultedUserId]);
            $this->sellerDepositDisposition->execute($auction, 'winner_default', $adminId, 'admin', $reason);

            return $auction->refresh();
        });
    }

    private function assertDefaultableSettlement(Auction $auction, $settlement): void
    {
        if (! $settlement->is_current || $settlement->current_marker !== 1) {
            throw new AuctionException(__('auction.errors.payment_target_not_current'));
        }

        if ($settlement->status !== SettlementStatus::PaymentPending) {
            if (in_array($settlement->status, [SettlementStatus::Paid, SettlementStatus::HandoverPending, SettlementStatus::Completed], true)) {
                throw new AuctionException(__('auction.errors.payment_obligation_already_paid'));
            }

            throw new AuctionException(__('auction.errors.winner_default_settlement_not_allowed'));
        }

        if ((int) $settlement->amount_due_minor <= 0 || (int) $settlement->amount_paid_minor >= (int) $settlement->amount_due_minor || (int) $settlement->remaining_amount_minor <= 0) {
            throw new AuctionException(__('auction.errors.payment_obligation_already_paid'));
        }

        if ((int) $auction->winning_bid_id !== (int) $settlement->winning_bid_id) {
            throw new AuctionException(__('auction.errors.winner_changed'));
        }

        $winningBid = AuctionBid::whereKey($settlement->winning_bid_id)->lockForUpdate()->firstOrFail();
        if ((int) $winningBid->bidder_id !== (int) $settlement->winner_id) {
            throw new AuctionException(__('auction.errors.winner_changed'));
        }
    }

    private function assertDeadline($settlement, bool $overrideDeadline, string $overrideReason, int $adminId): ?\Carbon\CarbonInterface
    {
        if (! $settlement->payment_due_at) {
            throw new AuctionException(__('auction.errors.payment_deadline_missing'));
        }

        if (Carbon::now()->greaterThan($settlement->payment_due_at)) {
            return null;
        }

        if (! $overrideDeadline) {
            throw new AuctionException(__('auction.errors.payment_deadline_not_expired'));
        }

        if (trim($overrideReason) === '') {
            throw new AuctionException(__('auction.errors.winner_default_override_reason_required'));
        }

        if (! $this->userHasPermission($adminId, self::OVERRIDE_DEADLINE_PERMISSION)) {
            throw new AuctionException(__('auction.errors.winner_default_override_not_authorized'));
        }

        return $settlement->payment_due_at;
    }

    private function supersedePendingSettlementSubmissions(int $settlementId, int $adminId): void
    {
        foreach ($this->payments->lockPendingReviewSubmissionsForSettlement($settlementId) as $submission) {
            $submission->forceFill([
                'status' => PaymentSubmissionStatus::Rejected,
                'reviewed_by' => $adminId,
                'review_note' => 'winner_default_superseded',
                'reviewed_at' => Carbon::now(),
            ]);
            $this->payments->save($submission);
        }
    }

    private function resolveDefaultedWinnerDeposit(
        Auction $auction,
        ?AuctionDeposit $deposit,
        WinnerDefaultDepositDispositionDTO $disposition,
        int $adminId
    ): void {
        if (! $deposit || $disposition->disposition === WinnerDefaultDepositDisposition::NoAction) {
            return;
        }

        match ($disposition->disposition) {
            WinnerDefaultDepositDisposition::FullForfeit => $this->forfeitDefaultedWinnerDeposit($auction, $deposit, $adminId),
            WinnerDefaultDepositDisposition::PartialForfeit => $this->partiallyForfeitDefaultedWinnerDeposit($auction, $deposit, $disposition, $adminId),
            WinnerDefaultDepositDisposition::Refund => $this->refundDeposit->execute($deposit, 'winner_default_refund', $adminId),
            WinnerDefaultDepositDisposition::ManualReview => $this->markDefaultedWinnerDepositForManualReview($auction, $deposit, $adminId),
            WinnerDefaultDepositDisposition::NoAction => null,
        };
    }

    private function forfeitDefaultedWinnerDeposit(Auction $auction, AuctionDeposit $deposit, int $adminId): void
    {
        $forfeited = (int) $deposit->held_amount_minor + (int) $deposit->applied_amount_minor;
        if ($forfeited <= 0) {
            return;
        }

        $deposit->forceFill([
            'status' => AuctionDepositStatus::Forfeited,
            'forfeited_amount_minor' => (int) $deposit->forfeited_amount_minor + $forfeited,
            'held_amount_minor' => 0,
            'applied_amount_minor' => 0,
            'hold_reason' => null,
            'released_at' => Carbon::now(),
        ]);
        $this->deposits->save($deposit);

        $this->audit->log('auction.winner_deposit_forfeited', $auction, $adminId, 'admin', [
            'deposit_public_id' => $deposit->public_id,
            'forfeited_amount_minor' => $forfeited,
        ]);
        $this->audit->outbox('auction.winner_deposit_forfeited', $auction, [
            'deposit_public_id' => $deposit->public_id,
            'forfeited_amount_minor' => $forfeited,
        ]);
    }

    private function partiallyForfeitDefaultedWinnerDeposit(
        Auction $auction,
        AuctionDeposit $deposit,
        WinnerDefaultDepositDispositionDTO $disposition,
        int $adminId
    ): void {
        $available = (int) $deposit->held_amount_minor + (int) $deposit->applied_amount_minor;
        $forfeitAmount = min($available, $disposition->forfeitAmountMinor);

        if ($forfeitAmount <= 0) {
            $this->markDefaultedWinnerDepositForManualReview($auction, $deposit, $adminId);

            return;
        }

        $heldForfeit = min((int) $deposit->held_amount_minor, $forfeitAmount);
        $appliedForfeit = min((int) $deposit->applied_amount_minor, $forfeitAmount - $heldForfeit);

        $deposit->forceFill([
            'held_amount_minor' => (int) $deposit->held_amount_minor - $heldForfeit,
            'applied_amount_minor' => (int) $deposit->applied_amount_minor - $appliedForfeit,
            'forfeited_amount_minor' => (int) $deposit->forfeited_amount_minor + $forfeitAmount,
        ]);
        $this->deposits->save($deposit);

        if (((int) $deposit->held_amount_minor + (int) $deposit->applied_amount_minor) > 0) {
            $this->refundDeposit->execute($deposit->refresh(), 'winner_default_partial_refund', $adminId);
        } else {
            $deposit->forceFill([
                'status' => AuctionDepositStatus::Forfeited,
                'released_at' => Carbon::now(),
            ]);
            $this->deposits->save($deposit);
        }

        $this->audit->outbox('auction.winner_deposit_forfeited', $auction, [
            'deposit_public_id' => $deposit->public_id,
            'forfeited_amount_minor' => $forfeitAmount,
            'partial' => true,
        ]);
    }

    private function markDefaultedWinnerDepositForManualReview(Auction $auction, AuctionDeposit $deposit, int $adminId): void
    {
        $deposit->forceFill([
            'hold_reason' => 'defaulted_winner_deposit_manual_review',
            'hold_metadata' => array_merge($deposit->hold_metadata ?? [], [
                'manual_review_reason' => 'winner_default',
            ]),
        ]);
        $this->deposits->save($deposit);

        $this->audit->log('auction.winner_deposit_manual_review', $auction, $adminId, 'admin', [
            'deposit_public_id' => $deposit->public_id,
        ]);
    }

    private function findEligibleAlternativeBid(Auction $auction, int $defaultedUserId): ?AuctionBid
    {
        $previouslyDefaultedUserIds = AuctionWinnerReassignment::where('auction_id', $auction->id)
            ->whereNotNull('from_user_id')
            ->pluck('from_user_id')
            ->map(fn ($id): int => (int) $id)
            ->push($defaultedUserId)
            ->unique()
            ->all();

        foreach ($this->bids->lockAlternativeWinnerCandidates($auction->id, $defaultedUserId) as $candidateBid) {
            if (in_array((int) $candidateBid->bidder_id, $previouslyDefaultedUserIds, true) || ! $candidateBid->accepted_at) {
                continue;
            }

            $participant = $candidateBid->participant;
            if (! $participant || $participant->status !== AuctionParticipantStatus::Qualified) {
                continue;
            }

            $deposit = $this->deposits->lockWinnerDeposit($auction->id, $candidateBid->bidder_id);
            if (! $deposit || $deposit->status !== AuctionDepositStatus::Held || $deposit->held_amount_minor < max(1, (int) $deposit->required_amount_minor)) {
                continue;
            }

            $snapshot = $this->snapshotReader->forAuction($auction);
            if (! $snapshot->terms_version_id || ! $this->terms->hasAcceptedTerms($auction->id, $candidateBid->bidder_id, (int) $snapshot->terms_version_id)) {
                continue;
            }

            return $candidateBid;
        }

        return null;
    }

    private function assignAlternativeWinner(
        Auction $auction,
        $defaultedSettlement,
        AuctionBid $newBid,
        int $adminId,
        string $reason
    ): Auction {
        $now = Carbon::now();
        $snapshot = $this->snapshotReader->forAuction($auction);

        $winningAmount = $newBid->amount_minor;
        $deposit = $this->deposits->lockWinnerDeposit($auction->id, $newBid->bidder_id);
        $depositApplied = $deposit ? min($deposit->held_amount_minor, $winningAmount) : 0;
        $platformFee = $snapshot->platformFeeFor($winningAmount);
        $sellerNet = $winningAmount - $platformFee;
        $amountDue = max(0, $winningAmount - $depositApplied);

        $reassignment = $this->winnerReassignments->firstOrCreate(
            [
                'auction_id' => $auction->id,
                'from_user_id' => $defaultedSettlement->winner_id,
                'previous_settlement_id' => $defaultedSettlement->id,
            ],
            [
                'from_bid_id' => $defaultedSettlement->winning_bid_id,
                'to_bid_id' => $newBid->id,
                'to_user_id' => $newBid->bidder_id,
                'created_by' => $adminId,
                'reason' => $reason,
                'metadata' => [
                    'previous_amount_minor' => $defaultedSettlement->winning_amount_minor,
                    'new_amount_minor' => $winningAmount,
                ],
                'created_at' => $now,
            ]
        );

        $newSettlement = $this->settlements->createSettlement(new CreateSettlementDTO(
            auctionId: $auction->id,
            winningBidId: $newBid->id,
            winnerId: $newBid->bidder_id,
            status: $amountDue > 0 ? SettlementStatus::PaymentPending : SettlementStatus::Paid,
            winningAmountMinor: $winningAmount,
            depositAppliedMinor: $depositApplied,
            platformFeeMinor: $platformFee,
            sellerNetAmountMinor: $sellerNet,
            amountDueMinor: $amountDue,
            amountPaidMinor: 0,
            remainingAmountMinor: $amountDue,
            currencyCode: $snapshot->currency_code,
            paymentDueAt: $amountDue > 0
                ? $now->copy()->addMinutes((int) $snapshot->winner_payment_deadline_minutes)
                : null,
            handoverDueAt: $amountDue === 0
                ? $now->copy()->addMinutes((int) $snapshot->handover_deadline_minutes)
                : null,
            paidAt: $amountDue === 0 ? $now : null,
            previousSettlementId: $defaultedSettlement->id,
            winnerReassignmentId: $reassignment->id,
        ));

        if (! $reassignment->new_settlement_id) {
            $reassignment->forceFill(['new_settlement_id' => $newSettlement->id]);
            $this->winnerReassignments->save($reassignment);
        }

        if ($deposit && $depositApplied > 0) {
            $deposit->forceFill([
                'status' => AuctionDepositStatus::AppliedToSettlement,
                'applied_amount_minor' => $depositApplied,
                'held_amount_minor' => $deposit->held_amount_minor - $depositApplied,
                'hold_reason' => null,
            ]);
            $this->deposits->save($deposit);
        }

        $auction->forceFill(['winning_bid_id' => $newBid->id]);
        $this->auctions->save($auction);

        $this->audit->log('auction.alternative_winner_selected', $auction, $adminId, 'admin', [
            'previous_winner_id' => $defaultedSettlement->winner_id,
            'new_winner_id' => $newBid->bidder_id,
            'new_bid_public_id' => $newBid->public_id,
            'new_settlement_public_id' => $newSettlement->public_id,
        ]);
        $this->audit->outbox('auction.alternative_winner_selected', $auction, [
            'auction_public_id' => $auction->public_id,
            'new_winner_id' => $newBid->bidder_id,
            'new_amount_minor' => $winningAmount,
        ]);
        $this->audit->outbox('auction.alternative_settlement_created', $auction, [
            'auction_public_id' => $auction->public_id,
            'previous_settlement_public_id' => $defaultedSettlement->public_id,
            'new_settlement_public_id' => $newSettlement->public_id,
        ]);

        $targetStatus = $amountDue > 0 ? AuctionStatus::PaymentPending : AuctionStatus::HandoverPending;

        return $this->stateMachine->transition($auction, $targetStatus, $adminId, 'admin', $reason);
    }

    private function userHasPermission(int $userId, string $permission): bool
    {
        $user = User::find($userId);

        if (! $user) {
            return false;
        }

        $explicit = $user->getAttribute('auction_permissions');

        if (is_string($explicit)) {
            $decoded = json_decode($explicit, true);
            $explicit = is_array($decoded) ? $decoded : [];
        }

        if (is_array($explicit) && in_array($permission, $explicit, true)) {
            return true;
        }

        return $user->role === 'admin'
            && in_array($permission, config('auction.admin_permissions', []), true);
    }
}
