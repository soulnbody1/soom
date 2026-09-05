<?php

declare(strict_types=1);

namespace App\Services\Auction\Support;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionDeposit;
use App\Repositories\Auction\AuctionDepositRepository;
use App\Repositories\Auction\AuctionParticipantRepository;
use App\Repositories\Auction\AuctionSettlementRepository;

final class PaymentObligationResolver
{
    public function __construct(
        private readonly AuctionDepositRepository $deposits,
        private readonly AuctionParticipantRepository $participants,
        private readonly AuctionSettlementRepository $settlements,
        private readonly PaymentEligibilityRule $eligibility,
        private readonly AuctionConfigurationSnapshotReader $snapshotReader,
    ) {}

    /**
     * Resolve the obligation a payment is meant to settle.
     *
     * With $lock = false the resolution is read-only: it takes no row locks and
     * creates nothing, so it is safe inside synchronous inbound reads. All
     * eligibility rules stay identical between both modes.
     */
    public function resolve(Auction $auction, int $userId, PaymentPurpose $purpose, bool $lock = true): PaymentObligation
    {
        if ($purpose === PaymentPurpose::SellerDeposit) {
            $this->eligibility->assertCanSubmitSellerDeposit($auction, $userId);
        }

        $snapshot = $this->snapshotReader->forAuction($auction);

        if ($purpose === PaymentPurpose::SellerDeposit) {
            if ($lock) {
                $this->deposits->firstOrCreateDeposit(
                    ['auction_id' => $auction->id, 'user_id' => $userId, 'type' => 'seller'],
                    [
                        'status' => AuctionDepositStatus::PendingSubmission,
                        'required_amount_minor' => (int) $snapshot->seller_deposit_required_minor,
                        'currency_code' => $snapshot->currency_code,
                    ]
                );
            }

            $deposit = $this->deposit($auction->id, $userId, 'seller', $lock);
            $this->eligibility->assertDepositTarget($auction, $deposit, $userId, 'seller');
            $this->eligibility->assertPaymentDetails(
                (int) $snapshot->seller_deposit_required_minor,
                $snapshot->currency_code,
                (int) $deposit->required_amount_minor,
                (string) $deposit->currency_code
            );

            return new PaymentObligation(
                $deposit,
                null,
                (int) $snapshot->seller_deposit_required_minor,
                (string) $snapshot->currency_code,
                $auction->seller_deposit_due_at
            );
        }

        if ($purpose === PaymentPurpose::BidderDeposit) {
            $participant = $lock
                ? $this->participants->lockParticipant($auction->id, $userId)
                : $this->participants->findParticipant($auction->id, $userId);

            if (! $participant) {
                throw AuctionException::domain('registration_required');
            }

            $this->eligibility->assertCanSubmitBidderDeposit($auction, $participant, $userId);

            if ($lock) {
                $this->deposits->firstOrCreateDeposit(
                    ['auction_id' => $auction->id, 'user_id' => $userId, 'type' => 'bidder'],
                    [
                        'participant_id' => $participant->id,
                        'status' => AuctionDepositStatus::PendingSubmission,
                        'required_amount_minor' => (int) $snapshot->bidder_deposit_required_minor,
                        'currency_code' => $snapshot->currency_code,
                    ]
                );
            }

            $deposit = $this->deposit($auction->id, $userId, 'bidder', $lock);
            $this->eligibility->assertDepositTarget($auction, $deposit, $userId, 'bidder', $participant);
            $this->eligibility->assertPaymentDetails(
                (int) $snapshot->bidder_deposit_required_minor,
                $snapshot->currency_code,
                (int) $deposit->required_amount_minor,
                (string) $deposit->currency_code
            );

            return new PaymentObligation(
                $deposit,
                null,
                (int) $snapshot->bidder_deposit_required_minor,
                (string) $snapshot->currency_code,
                $auction->ends_at
            );
        }

        $settlement = $lock
            ? $this->settlements->lockCurrentSettlementForPayment($auction->id)
            : $this->settlements->findCurrentSettlementForPayment($auction->id);

        if (! $settlement) {
            throw AuctionException::domain('settlement_payment_unavailable');
        }

        $this->eligibility->assertCanSubmitWinnerSettlement($auction, $settlement, $userId);

        $remaining = max(0, (int) ($settlement->remaining_amount_minor ?? ($settlement->amount_due_minor - $settlement->amount_paid_minor)));

        return new PaymentObligation(
            null,
            $settlement,
            $remaining,
            (string) $settlement->currency_code,
            $settlement->payment_grace_ends_at ?? $settlement->payment_due_at
        );
    }

    private function deposit(int $auctionId, int $userId, string $type, bool $lock): AuctionDeposit
    {
        if ($lock) {
            return $this->deposits->lockDepositForPayment($auctionId, $userId, $type);
        }

        $deposit = $this->deposits->findDepositForPayment($auctionId, $userId, $type);

        if (! $deposit) {
            throw AuctionException::domain('payment_submission_obligation_mismatch');
        }

        return $deposit;
    }
}
