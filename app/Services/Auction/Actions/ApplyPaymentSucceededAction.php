<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\AuctionParticipantStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Enums\SettlementStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\AuctionSettlement;
use App\Models\Auction\PaymentTransaction;
use App\Repositories\Auction\AuctionDepositRepository;
use App\Repositories\Auction\AuctionParticipantRepository;
use App\Repositories\Auction\AuctionRepository;
use App\Repositories\Auction\AuctionSettlementRepository;
use App\Services\Auction\Support\AuctionAudit;
use App\Services\Auction\Support\AuctionConfigurationSnapshotReader;
use App\Services\Auction\Support\AuctionStateMachine;
use App\Services\Auction\Support\FinancialObligationKey;
use Illuminate\Support\Carbon;

final class ApplyPaymentSucceededAction
{
    public function __construct(
        private readonly AuctionStateMachine $stateMachine,
        private readonly AuctionAudit $audit,
        private readonly AuctionRepository $auctions,
        private readonly AuctionDepositRepository $deposits,
        private readonly AuctionParticipantRepository $participants,
        private readonly AuctionSettlementRepository $settlements,
        private readonly PlanNonWinnerDepositRefundsAction $nonWinnerDeposits,
        private readonly AuctionConfigurationSnapshotReader $snapshotReader,
    ) {}

    public function execute(PaymentTransaction $transaction, ?int $actorId, string $actorType): void
    {
        $auction = $this->auctions->lockAuctionForPayment((int) $transaction->auction_id);
        $obligationKey = (string) $transaction->successful_obligation_key;

        if ($obligationKey === '') {
            throw AuctionException::domain('payment_submission_obligation_mismatch');
        }

        match ($transaction->purpose) {
            PaymentPurpose::SellerDeposit => $this->applySellerDeposit(
                $auction,
                $this->lockDeposit($obligationKey, 'seller'),
                $transaction,
                $actorId,
                $actorType
            ),
            PaymentPurpose::BidderDeposit => $this->applyBidderDeposit(
                $auction,
                $this->lockDeposit($obligationKey, 'bidder'),
                $transaction
            ),
            PaymentPurpose::WinnerSettlement => $this->applyWinnerSettlement(
                $auction,
                $this->lockSettlement($auction, $obligationKey),
                $transaction,
                $actorId,
                $actorType
            ),
        };
    }

    private function applySellerDeposit(
        Auction $auction,
        AuctionDeposit $deposit,
        PaymentTransaction $transaction,
        ?int $actorId,
        string $actorType
    ): void {
        $deposit->forceFill([
            'status' => AuctionDepositStatus::Held,
            'held_amount_minor' => $transaction->amount_minor,
            'held_at' => Carbon::now(),
        ]);
        $this->deposits->save($deposit);

        $this->stateMachine->transition($auction, AuctionStatus::Scheduled, $actorId, $actorType, 'seller deposit approved');

        $this->audit->log('auction.seller_deposit_payment_approved', $auction, $actorId, $actorType, [
            'deposit_public_id' => $deposit->public_id,
            'held_amount_minor' => $deposit->held_amount_minor,
            'source_payment_submission_public_id' => $transaction->submission?->public_id,
        ]);
        $this->audit->outbox('auction.seller_deposit_held', $auction, [
            'deposit_public_id' => $deposit->public_id,
            'held_amount_minor' => $deposit->held_amount_minor,
        ]);
    }

    private function applyBidderDeposit(Auction $auction, AuctionDeposit $deposit, PaymentTransaction $transaction): void
    {
        $deposit->forceFill([
            'status' => AuctionDepositStatus::Held,
            'held_amount_minor' => $transaction->amount_minor,
            'held_at' => Carbon::now(),
        ]);
        $this->deposits->save($deposit);

        $participant = $this->participants->lockParticipant($auction->id, (int) $deposit->user_id);

        if (! $participant) {
            return;
        }

        $participant->forceFill([
            'status' => AuctionParticipantStatus::Qualified,
            'qualified_at' => Carbon::now(),
        ]);
        $this->participants->save($participant);
    }

    private function applyWinnerSettlement(
        Auction $auction,
        AuctionSettlement $settlement,
        PaymentTransaction $transaction,
        ?int $actorId,
        string $actorType
    ): void {
        $snapshot = $this->snapshotReader->forAuction($auction);

        $newPaid = (int) $settlement->amount_paid_minor + (int) $transaction->amount_minor;
        $remaining = max(0, (int) $settlement->amount_due_minor - $newPaid);
        $isFullyPaid = $newPaid >= (int) $settlement->amount_due_minor;

        $settlement->forceFill([
            'amount_paid_minor' => $newPaid,
            'remaining_amount_minor' => $remaining,
            'status' => $isFullyPaid ? SettlementStatus::Paid : SettlementStatus::PaymentPending,
            'paid_at' => $isFullyPaid ? Carbon::now() : $settlement->paid_at,
            'handover_due_at' => $isFullyPaid
                ? Carbon::now()->addMinutes((int) $snapshot->handover_deadline_minutes)
                : $settlement->handover_due_at,
        ]);
        $this->settlements->save($settlement);

        if (! $isFullyPaid) {
            return;
        }

        $this->stateMachine->transition($auction, AuctionStatus::HandoverPending, $actorId, $actorType, 'winner payment approved');
        $this->nonWinnerDeposits->execute($auction->refresh(), 'winner_payment', $actorId, $actorType);
    }

    private function lockDeposit(string $obligationKey, string $type): AuctionDeposit
    {
        $depositId = FinancialObligationKey::depositId($obligationKey);

        if ($depositId === null) {
            throw AuctionException::domain('payment_submission_obligation_mismatch');
        }

        $deposit = $this->deposits->lockById($depositId);

        if ($deposit->type !== $type) {
            throw AuctionException::domain('payment_submission_obligation_mismatch');
        }

        return $deposit;
    }

    private function lockSettlement(Auction $auction, string $obligationKey): AuctionSettlement
    {
        $settlementId = FinancialObligationKey::settlementId($obligationKey);

        if ($settlementId === null) {
            throw AuctionException::domain('payment_submission_obligation_mismatch');
        }

        $settlement = $this->settlements->lockById($settlementId);
        $current = $this->settlements->lockCurrentSettlementForPayment($auction->id);

        if (! $current || $current->id !== $settlement->id) {
            throw AuctionException::domain('payment_target_not_current');
        }

        return $settlement;
    }
}
