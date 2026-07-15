<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\AuctionParticipantStatus;
use App\DTO\Auction\DepositHoldDecisionDTO;
use App\DTO\Auction\NonWinnerDepositDispositionDTO;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionDeposit;
use App\Repositories\Auction\AuctionBidRepository;
use App\Repositories\Auction\AuctionDepositRepository;
use App\Repositories\Auction\AuctionPaymentRepository;
use App\Repositories\Auction\AuctionRefundRepository;
use App\Repositories\Auction\AuctionRepository;
use App\Repositories\Auction\AuctionSettlementRepository;
use App\Repositories\Auction\AuctionTermsRepository;
use App\Services\Auction\Support\AuctionAudit;
use App\Services\Auction\Support\AuctionConfigurationSnapshotReader;
use App\Services\Auction\Support\AuctionTransaction;
use App\Services\Auction\Support\DepositRefundAllocation;
use App\Services\Auction\Support\FinancialObligationKey;
use Illuminate\Support\Collection;

final class PlanNonWinnerDepositRefundsAction
{
    private const HOLD_REASON_ALTERNATIVE = 'alternative_winner_candidate';

    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionAudit $audit,
        private readonly AuctionRepository $auctions,
        private readonly AuctionBidRepository $bids,
        private readonly AuctionDepositRepository $deposits,
        private readonly AuctionPaymentRepository $payments,
        private readonly AuctionRefundRepository $refunds,
        private readonly AuctionSettlementRepository $settlements,
        private readonly AuctionTermsRepository $terms,
        private readonly RefundAuctionDepositAction $refundDeposit,
        private readonly AuctionConfigurationSnapshotReader $snapshotReader,
    ) {}

    /**
     * @return array<int, NonWinnerDepositDispositionDTO>
     */
    public function execute(
        Auction $auction,
        string $trigger,
        ?int $actorId = null,
        string $actorType = 'system',
        array $excludedUserIds = [],
    ): array {
        return $this->transaction->run(function () use ($auction, $trigger, $actorId, $actorType, $excludedUserIds): array {
            $auction = $this->auctions->lockForStateChange($auction->id)->loadMissing(['winningBid']);
            $currentSettlement = $this->settlements->lockCurrentSettlementForPayment($auction->id);
            $currentWinnerIds = $this->currentWinnerIds($auction, $currentSettlement?->winner_id);
            $deposits = $this->deposits->lockBidderDepositsForAuction($auction->id);

            // Without bidder deposits there is nothing to plan. An auction that was never
            // approved has no configuration snapshot, so it must not be read before this.
            if ($deposits->isEmpty()) {
                return [];
            }

            $snapshot = $this->snapshotReader->forAuction($auction);
            $depositByUser = $deposits->keyBy('user_id');
            $holdDecisions = $this->holdDecisions($auction, $trigger, $snapshot, $depositByUser, $currentWinnerIds, $excludedUserIds);
            $dispositions = [];

            foreach ($deposits as $deposit) {
                if (in_array((int) $deposit->user_id, $currentWinnerIds, true)) {
                    $dispositions[] = new NonWinnerDepositDispositionDTO($deposit->id, $deposit->user_id, 'keep_current_winner', 'current winner deposit');

                    continue;
                }

                if (in_array((int) $deposit->user_id, $excludedUserIds, true)) {
                    $dispositions[] = new NonWinnerDepositDispositionDTO($deposit->id, $deposit->user_id, 'excluded_from_non_winner_release', 'handled by another lifecycle');

                    continue;
                }

                $holdDecision = $holdDecisions[(int) $deposit->user_id] ?? null;
                if ($holdDecision && $deposit->status === AuctionDepositStatus::Held) {
                    $changed = $this->applyHoldDecision($deposit, $holdDecision);
                    $dispositions[] = new NonWinnerDepositDispositionDTO($deposit->id, $deposit->user_id, 'keep_held', self::HOLD_REASON_ALTERNATIVE);

                    if ($changed) {
                        $this->audit->log('auction.non_winner_deposit_held', $auction, $actorId, $actorType, [
                            'deposit_public_id' => $deposit->public_id,
                            ...$holdDecision->metadata(),
                        ]);
                    }

                    continue;
                }

                $dispositions[] = $this->releaseDeposit($auction, $deposit, $trigger, $actorId, $actorType);
            }

            return $dispositions;
        });
    }

    private function currentWinnerIds(Auction $auction, ?int $settlementWinnerId): array
    {
        return collect([
            $settlementWinnerId,
            $auction->winningBid?->bidder_id,
        ])
            ->filter()
            ->map(fn (int $id): int => $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, AuctionDeposit>  $depositByUser
     * @return array<int, DepositHoldDecisionDTO>
     */
    private function holdDecisions(
        Auction $auction,
        string $trigger,
        \App\Models\Auction\AuctionConfigurationSnapshot $snapshot,
        Collection $depositByUser,
        array $currentWinnerIds,
        array $excludedUserIds,
    ): array {
        if (in_array($trigger, ['winner_payment', 'no_alternative', 'unsold', 'completed', 'cancelled'], true)) {
            return [];
        }

        if ($snapshot->non_winner_deposit_hold_policy === 'refund_all_non_winners_immediately') {
            return [];
        }

        $limit = $snapshot->non_winner_deposit_hold_policy === 'hold_top_n_bidders_until_winner_payment'
            ? $this->topCandidateLimit($snapshot, $trigger)
            : PHP_INT_MAX;

        if ($limit <= 0) {
            return [];
        }

        $decisions = [];
        $rank = 0;
        $seenUsers = [];

        foreach ($this->bids->lockRankedBids($auction->id) as $bid) {
            $userId = (int) $bid->bidder_id;

            if (isset($seenUsers[$userId])) {
                continue;
            }
            $seenUsers[$userId] = true;

            if (in_array($userId, $currentWinnerIds, true) || in_array($userId, $excludedUserIds, true)) {
                continue;
            }

            $deposit = $depositByUser->get($userId);
            if (! $deposit || ! $this->isEligibleAlternativeCandidate($auction, $deposit)) {
                continue;
            }

            $rank++;
            if ($rank > $limit) {
                break;
            }

            $decisions[$userId] = new DepositHoldDecisionDTO(
                userId: $userId,
                candidateRank: $rank,
                policy: $snapshot->non_winner_deposit_hold_policy,
                releaseTrigger: 'winner_payment_or_alternative_need_ended',
            );
        }

        return $decisions;
    }

    private function isEligibleAlternativeCandidate(Auction $auction, AuctionDeposit $deposit): bool
    {
        if ($deposit->status !== AuctionDepositStatus::Held || (int) $deposit->held_amount_minor <= 0) {
            return false;
        }

        if (! $deposit->participant || $deposit->participant->status !== AuctionParticipantStatus::Qualified) {
            return false;
        }

        $snapshot = $this->snapshotReader->forAuction($auction);
        if ($snapshot->terms_version_id && ! $this->terms->hasAcceptedTerms($auction->id, $deposit->user_id, (int) $snapshot->terms_version_id)) {
            return false;
        }

        return $this->refunds->lockActiveOrSucceededForDeposit($deposit->id)->isEmpty();
    }

    private function releaseDeposit(
        Auction $auction,
        AuctionDeposit $deposit,
        string $trigger,
        ?int $actorId,
        string $actorType,
    ): NonWinnerDepositDispositionDTO {
        $payment = $this->payments->lockSucceededTransactionForObligation(FinancialObligationKey::forDeposit($deposit));

        if (! $payment) {
            if (in_array($deposit->status, [
                AuctionDepositStatus::Held,
                AuctionDepositStatus::AppliedToSettlement,
                AuctionDepositStatus::RefundPending,
            ], true)) {
                $deposit->forceFill([
                    'status' => AuctionDepositStatus::Rejected,
                    'held_amount_minor' => 0,
                    'applied_amount_minor' => 0,
                    'hold_reason' => null,
                    'hold_expires_at' => null,
                    'hold_metadata' => null,
                    'released_at' => now(),
                ]);
                $this->deposits->save($deposit);

                $this->audit->log('auction.non_winner_deposit_released_without_payment', $auction, $actorId, $actorType, [
                    'deposit_public_id' => $deposit->public_id,
                    'trigger' => $trigger,
                ]);
                $this->audit->outbox('auction.non_winner_deposit_released', $auction, [
                    'deposit_public_id' => $deposit->public_id,
                    'trigger' => $trigger,
                    'reason' => 'no_successful_payment_transaction',
                ]);
            }

            return new NonWinnerDepositDispositionDTO($deposit->id, $deposit->user_id, 'released_without_payment', 'no successful payment transaction');
        }

        $existingRefunds = $this->refunds->lockActiveOrSucceededForDeposit($deposit->id);
        $allocation = DepositRefundAllocation::calculateRefundableDepositAmount(
            $deposit,
            (int) $payment->amount_minor,
            $existingRefunds,
            $this->settlements->lockForDepositRefund($deposit)
        );

        if ($allocation->isEmpty()) {
            if ($existingRefunds->isNotEmpty() && $deposit->status !== AuctionDepositStatus::RefundPending) {
                $deposit->forceFill([
                    'status' => AuctionDepositStatus::RefundPending,
                    'hold_reason' => null,
                    'hold_expires_at' => null,
                    'hold_metadata' => null,
                ]);
                $this->deposits->save($deposit);
            }

            return new NonWinnerDepositDispositionDTO($deposit->id, $deposit->user_id, 'already_planned', 'active or completed refund already covers deposit');
        }

        $refund = $this->refundDeposit->execute($deposit, "non_winner_deposit_release: {$trigger}", $actorId);

        $deposit->refresh()->forceFill([
            'hold_reason' => null,
            'hold_expires_at' => null,
            'hold_metadata' => null,
        ]);
        $this->deposits->save($deposit);

        if ($refund->wasRecentlyCreated) {
            $this->audit->log('auction.non_winner_deposit_refund_planned', $auction, $actorId, $actorType, [
                'deposit_public_id' => $deposit->public_id,
                'refund_public_id' => $refund->public_id,
                'trigger' => $trigger,
                'source_payment_transaction_id' => $payment->id,
                'held_refund_amount_minor' => $allocation->heldAmountMinor,
                'applied_refund_amount_minor' => $allocation->appliedAmountMinor,
            ]);
            $this->audit->outbox('auction.non_winner_deposit_refund_planned', $auction, [
                'deposit_public_id' => $deposit->public_id,
                'refund_public_id' => $refund->public_id,
                'trigger' => $trigger,
                'amount_minor' => $refund->amount_minor,
            ]);
        }

        return new NonWinnerDepositDispositionDTO($deposit->id, $deposit->user_id, 'refund_planned', 'refund plan created or reused', $refund->id);
    }

    private function applyHoldDecision(AuctionDeposit $deposit, DepositHoldDecisionDTO $decision): bool
    {
        $metadata = $decision->metadata();
        $changed = $deposit->hold_reason !== self::HOLD_REASON_ALTERNATIVE
            || $deposit->hold_metadata !== $metadata
            || $deposit->hold_expires_at !== null;

        if (! $changed) {
            return false;
        }

        $deposit->forceFill([
            'hold_reason' => self::HOLD_REASON_ALTERNATIVE,
            'hold_expires_at' => null,
            'hold_metadata' => $metadata,
        ]);
        $this->deposits->save($deposit);

        return true;
    }

    private function topCandidateLimit(\App\Models\Auction\AuctionConfigurationSnapshot $snapshot, string $trigger): int
    {
        $limit = (int) $snapshot->alternative_candidate_limit;

        return $trigger === 'alternative_selected'
            ? max(0, $limit - 1)
            : max(0, $limit);
    }
}
